<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\OpenAI\Concerns\BuildsTools;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Maps\FinishReasonMap;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
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
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream
{
    use BuildsTools;
    use CallsTools;
    use ProcessRateLimits;

    protected StreamState $state;

    public function __construct(protected PendingRequest $client)
    {
        $this->state = new StreamState;
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function handle(Request $request): Generator
    {
        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        $this->state->reset()->withMessageId(EventID::generate());
        $reasoningItems = [];

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            if ($data['type'] === 'error') {
                $code = data_get($data, 'error.code', 'unknown_error');
                $message = data_get($data, 'error.message', 'No error message provided');

                if ($code === 'rate_limit_exceeded') {
                    throw new PrismRateLimitedException([]);
                }

                throw new PrismException(sprintf(
                    'Sending to model %s failed. Code: %s. Message: %s',
                    $request->model(),
                    $code,
                    $message
                ));
            }

            if ($data['type'] === 'response.created' && $this->state->shouldEmitStreamStart()) {
                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: $data['response']['model'] ?? 'unknown',
                    provider: 'openai',
                );

                $this->state->markStreamStarted();

                continue;
            }

            if ($this->hasReasoningSummaryDelta($data)) {
                $reasoningDelta = $this->extractReasoningSummaryDelta($data);

                if ($reasoningDelta !== '') {
                    if ($this->state->reasoningId() === '') {
                        $this->state->withReasoningId(EventID::generate());
                        yield new ThinkingStartEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            reasoningId: $this->state->reasoningId()
                        );
                    }

                    $this->state->appendThinking($reasoningDelta);

                    yield new ThinkingEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        delta: $reasoningDelta,
                        reasoningId: $this->state->reasoningId()
                    );
                }

                continue;
            }

            if ($this->hasReasoningItems($data)) {
                $reasoningItems = $this->extractReasoningItems($data, $reasoningItems);

                if ($this->state->reasoningId() !== '') {
                    yield new ThinkingCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        reasoningId: $this->state->reasoningId()
                    );
                    $this->state->withReasoningId('');
                }

                continue;
            }

            if ($this->hasToolCalls($data)) {
                $this->extractToolCalls($data, $reasoningItems);

                if ($this->isToolCallComplete($data)) {
                    $completedToolCall = $this->getCompletedToolCall($data);
                    if ($completedToolCall instanceof ToolCall) {
                        yield new ToolCallEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            toolCall: $completedToolCall,
                            messageId: $this->state->messageId()
                        );
                    }
                }

                continue;
            }

            $content = $this->extractOutputTextDelta($data);

            if ($content !== '') {
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

            if (data_get($data, 'type') === 'response.output_text.done' && $this->state->hasTextStarted()) {
                yield new TextCompleteEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    messageId: $this->state->messageId()
                );
            }

            if (data_get($data, 'type') === 'response.completed') {
                yield new StreamEndEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    finishReason: $this->mapFinishReason($data),
                    usage: new Usage(
                        promptTokens: data_get($data, 'response.usage.input_tokens'),
                        completionTokens: data_get($data, 'response.usage.output_tokens'),
                        cacheReadInputTokens: data_get($data, 'response.usage.input_tokens_details.cached_tokens'),
                        thoughtTokens: data_get($data, 'response.usage.output_tokens_details.reasoning_tokens')
                    )
                );
            }
        }

        if ($this->state->hasToolCalls()) {
            yield from $this->handleToolCalls($request, $depth);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $line = trim(substr($line, strlen('data: ')));

        if (Str::contains($line, 'DONE')) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('OpenAI', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $reasoningItems
     */
    protected function extractToolCalls(array $data, array $reasoningItems = []): void
    {
        $type = data_get($data, 'type', '');

        if ($type === 'response.output_item.added' && data_get($data, 'item.type') === 'function_call') {
            $index = (int) data_get($data, 'output_index', count($this->state->toolCalls()));

            $toolCall = [
                'id' => data_get($data, 'item.id'),
                'call_id' => data_get($data, 'item.call_id'),
                'name' => data_get($data, 'item.name'),
                'arguments' => '',
            ];

            // Associate with the most recent reasoning item if available
            if ($reasoningItems !== []) {
                $latestReasoning = end($reasoningItems);
                $toolCall['reasoning_id'] = $latestReasoning['id'];
                $toolCall['reasoning_summary'] = $latestReasoning['summary'] ?? [];
            }

            $this->state->addToolCall($index, $toolCall);

            return;
        }

        if ($type === 'response.function_call_arguments.delta') {
            // continue for now, only needed if we want to support streaming argument chunks
        }

        if ($type === 'response.function_call_arguments.done') {
            $callId = data_get($data, 'item_id');
            $arguments = data_get($data, 'arguments', '');

            $toolCalls = $this->state->toolCalls();
            foreach ($toolCalls as $index => $call) {
                if (($call['id'] ?? null) === $callId) {
                    if ($arguments !== '') {
                        $this->state->updateToolCall($index, ['arguments' => $arguments]);
                    }
                    break;
                }
            }
        }
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(Request $request, int $depth): Generator
    {
        $mappedToolCalls = $this->mapToolCalls($this->state->toolCalls());
        $toolResults = $this->callTools($request->tools(), $mappedToolCalls);

        foreach ($toolResults as $result) {
            yield new ToolResultEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolResult: $result,
                messageId: EventID::generate()
            );
        }

        $request->addMessage(new AssistantMessage($this->state->currentText(), $mappedToolCalls));
        $request->addMessage(new ToolResultMessage($toolResults));

        $depth++;

        if ($depth < $request->maxSteps()) {
            $nextResponse = $this->sendRequest($request);

            yield from $this->processStream($nextResponse, $request, $depth);
        }
    }

    /**
     * Convert raw tool call data to ToolCall objects.
     *
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return collect($toolCalls)
            ->map(fn ($toolCall): ToolCall => new ToolCall(
                id: data_get($toolCall, 'id'),
                name: data_get($toolCall, 'name'),
                arguments: data_get($toolCall, 'arguments'),
                resultId: data_get($toolCall, 'call_id'),
                reasoningId: data_get($toolCall, 'reasoning_id'),
                reasoningSummary: data_get($toolCall, 'reasoning_summary', []),
            ))
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        $type = data_get($data, 'type', '');

        if (data_get($data, 'item.type') === 'function_call') {
            return true;
        }

        return in_array($type, [
            'response.function_call_arguments.delta',
            'response.function_call_arguments.done',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasReasoningItems(array $data): bool
    {
        $type = data_get($data, 'type', '');

        return $type === 'response.output_item.done' && data_get($data, 'item.type') === 'reasoning';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $reasoningItems
     * @return array<int, array<string, mixed>>
     */
    protected function extractReasoningItems(array $data, array $reasoningItems): array
    {
        if (data_get($data, 'type') === 'response.output_item.done' && data_get($data, 'item.type') === 'reasoning') {
            $index = (int) data_get($data, 'output_index', count($reasoningItems));

            $reasoningItems[$index] = [
                'id' => data_get($data, 'item.id'),
                'summary' => data_get($data, 'item.summary', []),
            ];
        }

        return $reasoningItems;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        $eventType = Str::after(data_get($data, 'type'), 'response.');
        $lastOutputType = data_get($data, 'response.output.{last}.type');

        return FinishReasonMap::map($eventType, $lastOutputType);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function isToolCallComplete(array $data): bool
    {
        return data_get($data, 'type') === 'response.function_call_arguments.done';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function getCompletedToolCall(array $data): ?ToolCall
    {
        $callId = data_get($data, 'item_id');

        foreach ($this->state->toolCalls() as $call) {
            if (($call['id'] ?? null) === $callId) {
                return new ToolCall(
                    id: $call['id'],
                    name: $call['name'],
                    arguments: $call['arguments'] ?? '',
                    resultId: $call['call_id'] ?? null,
                    reasoningId: $call['reasoning_id'] ?? null,
                    reasoningSummary: $call['reasoning_summary'] ?? []
                );
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasReasoningSummaryDelta(array $data): bool
    {
        $type = data_get($data, 'type', '');

        return $type === 'response.reasoning_summary_text.delta';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractReasoningSummaryDelta(array $data): string
    {
        if (data_get($data, 'type') === 'response.reasoning_summary_text.delta') {
            return (string) data_get($data, 'delta', '');
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractOutputTextDelta(array $data): string
    {
        if (data_get($data, 'type') === 'response.output_text.delta') {
            return (string) data_get($data, 'delta', '');
        }

        return '';
    }

    protected function sendRequest(Request $request): Response
    {
        return $this
            ->client
            ->withOptions(['stream' => true])
            ->post(
                'responses',
                array_merge([
                    'stream' => true,
                    'model' => $request->model(),
                    'input' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                    'max_output_tokens' => $request->maxTokens(),
                ], Arr::whereNotNull([
                    'temperature' => $request->temperature(),
                    'top_p' => $request->topP(),
                    'metadata' => $request->providerOptions('metadata'),
                    'tools' => $this->buildTools($request),
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                    'parallel_tool_calls' => $request->providerOptions('parallel_tool_calls'),
                    'previous_response_id' => $request->providerOptions('previous_response_id'),
                    'truncation' => $request->providerOptions('truncation'),
                    'reasoning' => $request->providerOptions('reasoning'),
                ]))
            );
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
}
