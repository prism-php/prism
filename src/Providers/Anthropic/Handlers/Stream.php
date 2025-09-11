<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Providers\Anthropic\Maps\CitationsMapper;
use Prism\Prism\Streaming\Events\CitationEvent;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream
{
    use CallsTools;

    protected string $messageId = '';

    protected string $reasoningId = '';

    protected string $currentText = '';

    protected string $currentThinking = '';

    protected string $currentThinkingSignature = '';

    protected ?int $currentBlockIndex = null;

    protected ?string $currentBlockType = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $toolCalls = [];

    /**
     * @var array<MessagePartWithCitations>
     */
    protected array $citations = [];

    protected ?Usage $usage = null;

    public function __construct(protected PendingRequest $client) {}

    /**
     * @return Generator<StreamEvent>
     */
    public function handle(Request $request): Generator
    {
        $this->resetState();
        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        while (! $response->getBody()->eof()) {
            $event = $this->parseNextSSEEvent($response->getBody());

            if ($event === null) {
                continue;
            }

            $streamEvent = $this->processEvent($event);

            if ($streamEvent instanceof Generator) {
                yield from $streamEvent;
            } elseif ($streamEvent instanceof StreamEvent) {
                yield $streamEvent;
            }
        }

        // Handle tool calls if present
        if ($this->toolCalls !== []) {
            yield from $this->handleToolCalls($request, $depth);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return StreamEvent|Generator<StreamEvent>|null
     */
    protected function processEvent(array $event): StreamEvent|Generator|null
    {
        return match ($event['type'] ?? null) {
            'message_start' => $this->handleMessageStart($event),
            'content_block_start' => $this->handleContentBlockStart($event),
            'content_block_delta' => $this->handleContentBlockDelta($event),
            'content_block_stop' => $this->handleContentBlockStop($event),
            'message_delta' => $this->handleMessageDelta($event),
            'message_stop' => $this->handleMessageStop($event),
            'error' => $this->handleError($event),
            'ping' => null, // Ignore ping events
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleMessageStart(array $event): StreamStartEvent
    {
        $message = $event['message'] ?? [];
        $this->messageId = $message['id'] ?? $this->generateEventId();

        // Capture initial usage data
        $usageData = $message['usage'] ?? [];
        if (! empty($usageData)) {
            $this->usage = new Usage(
                promptTokens: $usageData['input_tokens'] ?? 0,
                completionTokens: $usageData['output_tokens'] ?? 0,
                cacheWriteInputTokens: $usageData['cache_creation_input_tokens'] ?? null,
                cacheReadInputTokens: $usageData['cache_read_input_tokens'] ?? null
            );
        }

        return new StreamStartEvent(
            id: $this->generateEventId(),
            timestamp: time(),
            model: $message['model'] ?? 'unknown',
            provider: 'anthropic'
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleContentBlockStart(array $event): ?StreamEvent
    {
        $this->currentBlockIndex = $event['index'] ?? null;
        $contentBlock = $event['content_block'] ?? [];
        $this->currentBlockType = $contentBlock['type'] ?? null;

        return match ($this->currentBlockType) {
            'text' => new TextStartEvent(
                id: $this->generateEventId(),
                timestamp: time(),
                messageId: $this->messageId
            ),
            'thinking' => $this->handleThinkingStart(),
            'tool_use' => $this->handleToolUseStart($contentBlock),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleContentBlockDelta(array $event): ?StreamEvent
    {
        $delta = $event['delta'] ?? [];
        $deltaType = $delta['type'] ?? null;

        return match ([$this->currentBlockType, $deltaType]) {
            ['text', 'text_delta'] => $this->handleTextDelta($delta),
            ['text', 'citations_delta'] => $this->handleCitationsDelta($delta),
            ['thinking', 'thinking_delta'] => $this->handleThinkingDelta($delta),
            ['thinking', 'signature_delta'] => $this->handleSignatureDelta($delta),
            ['tool_use', 'input_json_delta'] => $this->handleToolInputDelta($delta),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleContentBlockStop(array $event): ?StreamEvent
    {
        $result = match ($this->currentBlockType) {
            'text' => new TextCompleteEvent(
                id: $this->generateEventId(),
                timestamp: time(),
                messageId: $this->messageId
            ),
            'thinking' => new ThinkingCompleteEvent(
                id: $this->generateEventId(),
                timestamp: time(),
                reasoningId: $this->reasoningId
            ),
            'tool_use' => $this->handleToolUseComplete(),
            default => null,
        };

        $this->resetCurrentBlock();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleMessageDelta(array $event): null
    {
        // Update usage with final data from message_delta
        $usageData = $event['usage'] ?? [];
        // Update completion tokens if provided
        if (! empty($usageData) && $this->usage instanceof \Prism\Prism\ValueObjects\Usage && isset($usageData['output_tokens'])) {
            $this->usage = new Usage(
                promptTokens: $this->usage->promptTokens,
                completionTokens: $usageData['output_tokens'],
                cacheWriteInputTokens: $this->usage->cacheWriteInputTokens,
                cacheReadInputTokens: $this->usage->cacheReadInputTokens
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleMessageStop(array $event): StreamEndEvent
    {
        return new StreamEndEvent(
            id: $this->generateEventId(),
            timestamp: time(),
            finishReason: FinishReason::Stop, // Default, will be updated by message_delta
            usage: $this->usage,
            citations: $this->citations !== [] ? $this->citations : null
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleError(array $event): ErrorEvent
    {
        return new ErrorEvent(
            id: $this->generateEventId(),
            timestamp: time(),
            errorType: $event['error']['type'] ?? 'unknown_error',
            message: $event['error']['message'] ?? 'Unknown error occurred',
            recoverable: true
        );
    }

    protected function handleThinkingStart(): ThinkingStartEvent
    {
        $this->reasoningId = $this->generateEventId();

        return new ThinkingStartEvent(
            id: $this->generateEventId(),
            timestamp: time(),
            reasoningId: $this->reasoningId
        );
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function handleTextDelta(array $delta): ?TextDeltaEvent
    {
        $text = $delta['text'] ?? '';

        if ($text === '') {
            return null;
        }

        $this->currentText .= $text;

        return new TextDeltaEvent(
            id: $this->generateEventId(),
            timestamp: time(),
            delta: $text,
            messageId: $this->messageId
        );
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function handleCitationsDelta(array $delta): ?CitationEvent
    {
        $citationData = $delta['citation'] ?? null;

        if ($citationData === null) {
            return null;
        }

        // Map citation data using CitationsMapper
        $citation = CitationsMapper::mapCitationFromAnthropic($citationData);

        // Create MessagePartWithCitations for aggregation
        $messagePartWithCitations = new MessagePartWithCitations(
            outputText: $this->currentText,
            citations: [$citation]
        );

        // Store for later aggregation
        $this->citations[] = $messagePartWithCitations;

        return new CitationEvent(
            id: $this->generateEventId(),
            timestamp: time(),
            citation: $citation,
            messageId: $this->messageId,
            blockIndex: $this->currentBlockIndex
        );
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function handleThinkingDelta(array $delta): ?ThinkingEvent
    {
        $thinking = $delta['thinking'] ?? '';

        if ($thinking === '') {
            return null;
        }

        $this->currentThinking .= $thinking;

        return new ThinkingEvent(
            id: $this->generateEventId(),
            timestamp: time(),
            delta: $thinking,
            reasoningId: $this->reasoningId
        );
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function handleSignatureDelta(array $delta): null
    {
        $this->currentThinkingSignature .= $delta['signature'] ?? '';

        return null;
    }

    /**
     * @param  array<string, mixed>  $contentBlock
     */
    protected function handleToolUseStart(array $contentBlock): null
    {
        if ($this->currentBlockIndex !== null) {
            $this->toolCalls[$this->currentBlockIndex] = [
                'id' => $contentBlock['id'] ?? $this->generateEventId(),
                'name' => $contentBlock['name'] ?? 'unknown',
                'input' => '',
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function handleToolInputDelta(array $delta): null
    {
        $partialJson = $delta['partial_json'] ?? '';

        if ($this->currentBlockIndex !== null && isset($this->toolCalls[$this->currentBlockIndex])) {
            $this->toolCalls[$this->currentBlockIndex]['input'] .= $partialJson;
        }

        return null;
    }

    protected function handleToolUseComplete(): ?ToolCallEvent
    {
        if ($this->currentBlockIndex === null || ! isset($this->toolCalls[$this->currentBlockIndex])) {
            return null;
        }

        $toolCall = $this->toolCalls[$this->currentBlockIndex];
        $input = $toolCall['input'];

        // Parse the JSON input
        if (is_string($input) && json_validate($input)) {
            $input = json_decode($input, true);
        } elseif (is_string($input) && $input !== '') {
            // If it's not valid JSON but not empty, wrap in array
            $input = ['input' => $input];
        } else {
            $input = [];
        }

        $toolCallObj = new ToolCall(
            id: $toolCall['id'],
            name: $toolCall['name'],
            arguments: $input,
            reasoningId: $this->reasoningId !== '' ? $this->reasoningId : null
        );

        return new ToolCallEvent(
            id: $this->generateEventId(),
            timestamp: time(),
            toolCall: $toolCallObj,
            messageId: $this->messageId
        );
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(Request $request, int $depth): Generator
    {
        $toolCalls = [];

        // Convert tool calls to ToolCall objects
        foreach ($this->toolCalls as $toolCallData) {
            $input = $toolCallData['input'];
            if (is_string($input) && json_validate($input)) {
                $input = json_decode($input, true);
            } elseif (is_string($input) && $input !== '') {
                $input = ['input' => $input];
            } else {
                $input = [];
            }

            $toolCalls[] = new ToolCall(
                id: $toolCallData['id'],
                name: $toolCallData['name'],
                arguments: $input
            );
        }

        // Execute tools and emit results
        $toolResults = [];
        foreach ($toolCalls as $toolCall) {
            try {
                $tool = $this->resolveTool($toolCall->name, $request->tools());
                $result = call_user_func_array($tool->handle(...), $toolCall->arguments());

                $toolResult = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: $result
                );

                $toolResults[] = $toolResult;

                $resultObj = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: is_array($result) ? $result : ['result' => $result]
                );

                yield new ToolResultEvent(
                    id: $this->generateEventId(),
                    timestamp: time(),
                    toolResult: $resultObj,
                    messageId: $this->messageId,
                    success: true
                );
            } catch (Throwable $e) {
                $errorResultObj = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: []
                );

                yield new ToolResultEvent(
                    id: $this->generateEventId(),
                    timestamp: time(),
                    toolResult: $errorResultObj,
                    messageId: $this->messageId,
                    success: false,
                    error: $e->getMessage()
                );
            }
        }

        // Add messages to request for next turn
        if ($toolResults !== []) {
            $request->addMessage(new AssistantMessage(
                content: $this->currentText,
                toolCalls: $toolCalls
            ));

            $request->addMessage(new ToolResultMessage($toolResults));

            // Continue streaming if within step limit
            $depth++;
            if ($depth < $request->maxSteps()) {
                $this->resetState();
                $nextResponse = $this->sendRequest($request);
                yield from $this->processStream($nextResponse, $request, $depth);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseNextSSEEvent(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);
        $line = trim($line);

        if ($line === '' || $line === '0') {
            return null;
        }

        if (str_starts_with($line, 'event:')) {
            return $this->parseEventChunk($line, $stream);
        }

        if (str_starts_with($line, 'data:')) {
            return $this->parseDataChunk($line);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseEventChunk(string $line, StreamInterface $stream): ?array
    {
        $eventType = trim(substr($line, strlen('event:')));

        if ($eventType === 'ping') {
            return ['type' => 'ping'];
        }

        $dataLine = $this->readLine($stream);
        $dataLine = trim($dataLine);

        if ($dataLine === '' || $dataLine === '0' || ! str_starts_with($dataLine, 'data:')) {
            return ['type' => $eventType];
        }

        return $this->parseJsonData($dataLine, $eventType);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseDataChunk(string $line): ?array
    {
        $jsonData = trim(substr($line, strlen('data:')));

        if ($jsonData === '' || $jsonData === '0' || str_contains($jsonData, 'DONE')) {
            return null;
        }

        return $this->parseJsonData($jsonData);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseJsonData(string $jsonDataLine, ?string $eventType = null): ?array
    {
        $jsonData = trim(str_starts_with($jsonDataLine, 'data:')
            ? substr($jsonDataLine, strlen('data:'))
            : $jsonDataLine);

        if ($jsonData === '' || $jsonData === '0') {
            return $eventType ? ['type' => $eventType] : null;
        }

        try {
            $data = json_decode($jsonData, true, flags: JSON_THROW_ON_ERROR);

            if ($eventType) {
                $data['type'] = $eventType;
            }

            return $data;
        } catch (Throwable $e) {
            throw new PrismChunkDecodeException('Anthropic', $e);
        }
    }

    protected function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (! $stream->eof()) {
            $byte = $stream->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    protected function sendRequest(Request $request): Response
    {
        return $this->client
            ->withOptions(['stream' => true])
            ->post('messages', Arr::whereNotNull([
                'stream' => true,
                ...Text::buildHttpRequestPayload($request),
            ]));
    }

    protected function generateEventId(): string
    {
        return 'evt_'.Str::random(16);
    }

    protected function resetState(): void
    {
        $this->messageId = '';
        $this->reasoningId = '';
        $this->currentText = '';
        $this->currentThinking = '';
        $this->currentThinkingSignature = '';
        $this->currentBlockIndex = null;
        $this->currentBlockType = null;
        $this->toolCalls = [];
        $this->citations = [];
        $this->usage = null;
    }

    protected function resetCurrentBlock(): void
    {
        $this->currentBlockIndex = null;
        $this->currentBlockType = null;
    }
}
