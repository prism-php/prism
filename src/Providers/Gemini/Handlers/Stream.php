<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Gemini\Maps\FinishReasonMap;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
use Prism\Prism\Providers\Gemini\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Gemini\Maps\ToolMap;
use Prism\Prism\Streaming\EventID;
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
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Text\Request;
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

    protected StreamState $state;

    public function __construct(
        protected PendingRequest $client,
        #[\SensitiveParameter] protected string $apiKey,
    ) {
        $this->state = new StreamState;
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function handle(Request $request): Generator
    {
        $this->state->reset();
        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        // Prevent infinite recursion with tool calls
        if ($depth >= $request->maxSteps()) {
            throw new PrismException('Maximum tool call chain depth exceeded');
        }

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            // Skip empty data
            if ($data === null) {
                continue;
            }

            // Debug: Log the data structure to understand thinking content
            if (isset($_ENV['PRISM_DEBUG_GEMINI_STREAM'])) {
                error_log('Gemini Stream Data: '.json_encode($data, JSON_PRETTY_PRINT));
            }

            // Emit stream start event once
            if ($this->state->shouldEmitStreamStart()) {
                $this->state->withMessageId(EventID::generate());

                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: data_get($data, 'modelVersion', 'unknown'),
                    provider: 'gemini'
                );
                $this->state->markStreamStarted();
            }

            // Update usage data from each chunk
            $this->state->withUsage($this->extractUsage($data, $request));

            // Process tool calls
            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $this->state->toolCalls());
                foreach ($toolCalls as $index => $toolCall) {
                    $this->state->addToolCall($index, $toolCall);
                }

                // Emit tool call events
                foreach ($this->state->toolCalls() as $toolCallData) {
                    yield new ToolCallEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolCall: $this->mapToolCall($toolCallData),
                        messageId: $this->state->messageId()
                    );
                }

                // Check if this is the final part of the tool calls
                if ($this->mapFinishReason($data) === FinishReason::ToolCalls) {
                    yield from $this->handleToolCalls($request, $depth, $data);
                }

                continue;
            }

            // Handle content from all parts
            $parts = data_get($data, 'candidates.0.content.parts', []);

            foreach ($parts as $part) {
                // Check if this part is thinking content (based on Google's documentation)
                if (isset($part['thought']) && $part['thought'] === true) {
                    // Handle thinking content - part has thought=true boolean field
                    $thinkingContent = $part['text'] ?? '';

                    if ($thinkingContent !== '') {
                        // Start thinking if not already started
                        if ($this->state->reasoningId() === '') {
                            $this->state->withReasoningId(EventID::generate());

                            yield new ThinkingStartEvent(
                                id: EventID::generate(),
                                timestamp: time(),
                                reasoningId: $this->state->reasoningId()
                            );
                        }

                        $this->state->appendThinking($thinkingContent);

                        yield new ThinkingEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            delta: $thinkingContent,
                            reasoningId: $this->state->reasoningId()
                        );
                    }
                } elseif (isset($part['text']) && (! isset($part['thought']) || $part['thought'] === false)) {
                    // Handle regular text content (only when thought is not true)
                    $content = $part['text'];

                    if ($content !== '') {
                        // Emit text start event once when we first get text
                        if ($this->state->shouldEmitTextStart()) {
                            yield new TextStartEvent(
                                id: EventID::generate(),
                                timestamp: time(),
                                messageId: $this->state->messageId()
                            );
                            $this->state->markTextStarted();
                        }

                        $this->state->appendText($content);

                        yield new TextDeltaEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            delta: $content,
                            messageId: $this->state->messageId()
                        );
                    }
                }
            }

            // Handle completion
            $finishReason = $this->mapFinishReason($data);

            if ($finishReason !== FinishReason::Unknown) {
                // Emit thinking complete if we had thinking
                if ($this->state->reasoningId() !== '') {
                    yield new ThinkingCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        reasoningId: $this->state->reasoningId()
                    );
                }

                // Emit text complete if we had text
                if ($this->state->hasTextStarted()) {
                    yield new TextCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId()
                    );
                }

                // Extract grounding metadata if available
                $groundingMetadata = $this->extractGroundingMetadata($data);

                // Emit stream end event
                yield new StreamEndEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    finishReason: $finishReason,
                    usage: $this->state->usage(),
                    additionalContent: Arr::whereNotNull([
                        'grounding_metadata' => $groundingMetadata,
                        'thoughtSummaries' => $this->state->thinkingSummaries() === [] ? null : $this->state->thinkingSummaries(),
                    ])
                );
            }
        }

        // Handle tool calls if present and not already handled
        if ($this->state->hasToolCalls() && $this->mapFinishReason([]) === FinishReason::Unknown) {
            yield from $this->handleToolCalls($request, $depth);
        }
    }

    /**
     * @return array<string, mixed>|null Parsed JSON data or null if line should be skipped
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $line = trim(substr($line, strlen('data: ')));

        if ($line === '' || $line === '[DONE]') {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('Gemini', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        $parts = data_get($data, 'candidates.0.content.parts', []);

        foreach ($parts as $index => $part) {
            if (isset($part['functionCall'])) {
                $toolCalls[$index]['id'] = EventID::generate('gm');
                $toolCalls[$index]['name'] = data_get($part, 'functionCall.name');
                $toolCalls[$index]['arguments'] = data_get($part, 'functionCall.args', []);
                $toolCalls[$index]['reasoningId'] = data_get($part, 'thoughtSignature');
            }
        }

        return $toolCalls;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(
        Request $request,
        int $depth,
        array $data = []
    ): Generator {
        $mappedToolCalls = [];

        // Convert tool calls to ToolCall objects
        foreach ($this->state->toolCalls() as $toolCallData) {
            $mappedToolCalls[] = $this->mapToolCall($toolCallData);
        }

        // Execute tools and emit results
        $toolResults = [];
        foreach ($mappedToolCalls as $toolCall) {
            try {
                $tool = $this->resolveTool($toolCall->name, $request->tools());
                $result = call_user_func_array($tool->handle(...), $toolCall->arguments());

                $toolResult = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: is_array($result) ? $result : ['result' => $result]
                );

                $toolResults[] = $toolResult;

                yield new ToolResultEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    toolResult: $toolResult,
                    messageId: $this->state->messageId(),
                    success: true
                );
            } catch (Throwable $e) {
                $errorResult = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: []
                );

                $toolResults[] = $errorResult;

                yield new ToolResultEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    toolResult: $errorResult,
                    messageId: $this->state->messageId(),
                    success: false,
                    error: $e->getMessage()
                );
            }
        }

        // Add messages for next turn and continue streaming
        if ($toolResults !== []) {
            $request->addMessage(new AssistantMessage($this->state->currentText(), $mappedToolCalls));
            $request->addMessage(new ToolResultMessage($toolResults));

            $depth++;
            if ($depth < $request->maxSteps()) {
                $this->state->reset();
                $nextResponse = $this->sendRequest($request);
                yield from $this->processStream($nextResponse, $request, $depth);
            }
        }
    }

    /**
     * Convert raw tool call data to ToolCall object.
     *
     * @param  array<string, mixed>  $toolCallData
     */
    protected function mapToolCall(array $toolCallData): ToolCall
    {
        $arguments = data_get($toolCallData, 'arguments', []);

        // If arguments is a string, try to decode it as JSON
        if (is_string($arguments) && $arguments !== '') {
            $decoded = json_decode($arguments, true);
            $arguments = json_last_error() === JSON_ERROR_NONE ? $decoded : ['input' => $arguments];
        }

        return new ToolCall(
            id: empty($toolCallData['id']) ? EventID::generate('gm') : $toolCallData['id'],
            name: data_get($toolCallData, 'name', 'unknown'),
            arguments: $arguments,
            reasoningId: data_get($toolCallData, 'reasoningId')
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        $parts = data_get($data, 'candidates.0.content.parts', []);

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data, Request $request): Usage
    {
        $providerOptions = $request->providerOptions();

        return new Usage(
            promptTokens: isset($providerOptions['cachedContentName'])
                ? (data_get($data, 'usageMetadata.promptTokenCount', 0) - data_get($data, 'usageMetadata.cachedContentTokenCount', 0))
                : data_get($data, 'usageMetadata.promptTokenCount', 0),
            completionTokens: data_get($data, 'usageMetadata.candidatesTokenCount', 0),
            cacheReadInputTokens: data_get($data, 'usageMetadata.cachedContentTokenCount'),
            thoughtTokens: data_get($data, 'usageMetadata.thoughtsTokenCount'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        $finishReason = data_get($data, 'candidates.0.finishReason');

        if (! $finishReason) {
            return FinishReason::Unknown;
        }

        $isToolCall = $this->hasToolCalls($data);

        return FinishReasonMap::map($finishReason, $isToolCall);
    }

    protected function sendRequest(Request $request): Response
    {
        $providerOptions = $request->providerOptions();

        if ($request->tools() !== [] && $request->providerTools() !== []) {
            throw new PrismException('Use of provider tools with custom tools is not currently supported by Gemini.');
        }

        if ($request->tools() !== [] && ($providerOptions['searchGrounding'] ?? false)) {
            throw new PrismException('Use of search grounding with custom tools is not currently supported by Prism.');
        }

        $tools = [];

        if ($request->providerTools() !== []) {
            $tools = array_map(
                fn ($providerTool): array => [
                    $providerTool->type => $providerTool->options !== [] ? $providerTool->options : (object) [],
                ],
                $request->providerTools()
            );
        } elseif ($providerOptions['searchGrounding'] ?? false) {
            $tools = [
                [
                    'google_search' => (object) [],
                ],
            ];
        } elseif ($request->tools() !== []) {
            $tools = ['function_declarations' => ToolMap::map($request->tools())];
        }

        $thinkingConfig = $providerOptions['thinkingConfig'] ?? null;

        if (isset($providerOptions['thinkingBudget'])) {
            $thinkingConfig = [
                'thinkingBudget' => $providerOptions['thinkingBudget'],
                'includeThoughts' => true,
            ];
        }

        if (isset($providerOptions['thinkingLevel'])) {
            $thinkingConfig = [
                'thinkingLevel' => $providerOptions['thinkingLevel'],
                'includeThoughts' => true,
            ];
        }

        /** @var Response $response */
        $response = $this->client
            ->withOptions(['stream' => true])
            ->post(
                "{$request->model()}:streamGenerateContent?alt=sse",
                Arr::whereNotNull([
                    ...(new MessageMap($request->messages(), $request->systemPrompts()))(),
                    'cachedContent' => $providerOptions['cachedContentName'] ?? null,
                    'generationConfig' => Arr::whereNotNull([
                        'temperature' => $request->temperature(),
                        'topP' => $request->topP(),
                        'maxOutputTokens' => $request->maxTokens(),
                        'thinkingConfig' => $thinkingConfig,
                    ]) ?: null,
                    'tools' => $tools !== [] ? $tools : null,
                    'tool_config' => $request->toolChoice() ? ToolChoiceMap::map($request->toolChoice()) : null,
                    'safetySettings' => $providerOptions['safetySettings'] ?? null,
                ])
            );

        return $response;
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

    /**
     * Check if a part contains thinking content based on Gemini's structure
     *
     * @param  array<string, mixed>  $part
     */
    protected function isThinkingContent(array $part): bool
    {
        // According to Google's documentation, thinking content is marked with thought=true
        return isset($part['thought']) && $part['thought'] === true;
    }

    /**
     * Extract grounding metadata from Gemini API response
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function extractGroundingMetadata(array $data): ?array
    {
        $groundingMetadata = data_get($data, 'candidates.0.groundingMetadata');

        if (! $groundingMetadata) {
            return null;
        }

        return $groundingMetadata;
    }
}
