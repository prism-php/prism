<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\OpenAI\Concerns\BuildsTools;
use Prism\Prism\Providers\OpenAI\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenAI\Concerns\MapsUsage;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use Prism\Prism\Providers\OpenAI\Support\OutputItems;
use Prism\Prism\Providers\OpenAI\ValueObjects\OpenAIStreamState;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
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
    use MapsFinishReason;
    use MapsUsage;
    use ProcessRateLimits;

    protected OpenAIStreamState $state;

    public function __construct(protected PendingRequest $client)
    {
        $this->state = new OpenAIStreamState;
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

            if ($this->state->shouldEmitStepStart()) {
                $this->state->markStepStarted();

                yield new StepStartEvent(
                    id: EventID::generate(),
                    timestamp: time()
                );
            }

            $this->captureOutputItem($data);

            if ($this->isRefusalEvent($data)) {
                $this->captureRefusal($data);

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

            if (data_get($data, 'type') === 'response.output_item.done') {
                $item = data_get($data, 'item', []);
                $itemType = data_get($item, 'type', '');

                if ($itemType !== 'function_call' && str_ends_with((string) $itemType, '_call')) {
                    yield new ProviderToolEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolType: $itemType,
                        status: 'completed',
                        itemId: data_get($item, 'id', ''),
                        data: $item
                    );

                    continue;
                }
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
                $toolCallDeltaEvent = $this->extractToolCalls($data, $reasoningItems);

                if ($toolCallDeltaEvent instanceof ToolCallDeltaEvent) {
                    yield $toolCallDeltaEvent;
                }

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

            $type = (string) data_get($data, 'type', '');

            if (str_starts_with($type, 'response.') && str_contains($type, '_call.')) {
                $parts = explode('.', $type, 3);

                if (count($parts) === 3 && str_ends_with($parts[1], '_call')) {
                    $toolType = $parts[1];
                    $status = $parts[2];

                    yield new ProviderToolEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolType: $toolType,
                        status: $status,
                        itemId: data_get($data, 'item_id', ''),
                        data: $data
                    );

                    continue;
                }
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
                $this->state->markTextCompleted();

                yield new TextCompleteEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    messageId: $this->state->messageId()
                );
            }

            if ($this->isTerminalEvent($data)) {
                yield from $this->handleTerminalEvent($data);
            }
        }

        yield from $this->completeOpenBlocks();

        if ($this->shouldExecuteToolCalls()) {
            yield from $this->handleToolCalls($request, $depth);

            return;
        }

        $this->state->markStepFinished();
        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time(),
            usage: $this->state->stepUsage(),
            additionalContent: $this->stepAdditionalContent(),
            finishReason: $this->state->finishReason() ?? FinishReason::Stop,
        );

        yield $this->emitStreamEndEvent();
    }

    /**
     * A response that fails or a stream that drops before its `.done` events
     * still closes the reasoning and text it started.
     *
     * @return Generator<StreamEvent>
     */
    protected function completeOpenBlocks(): Generator
    {
        if ($this->state->reasoningId() !== '') {
            yield new ThinkingCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                reasoningId: $this->state->reasoningId()
            );

            $this->state->withReasoningId('');
        }

        if ($this->state->hasTextStarted()) {
            $this->state->markTextCompleted();

            yield new TextCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $this->state->messageId()
            );
        }
    }

    protected function emitStreamEndEvent(): StreamEndEvent
    {
        $terminalResponse = $this->state->terminalResponse();

        return new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: $this->state->finishReason() ?? FinishReason::Stop,
            usage: $this->state->usage() ?? new Usage(0, 0),
            additionalContent: Arr::whereNotNull([
                'response_id' => $this->state->metadata()['response_id'] ?? null,
                'reasoningSummaries' => $this->state->thinkingSummaries() === [] ? null : $this->state->thinkingSummaries(),
                'phase' => OutputItems::phase($this->state->outputItems()),
                'refusal' => $this->state->refusal(),
                'status' => $terminalResponse['status'] ?? null,
                'incomplete_details' => $terminalResponse['incomplete_details'] ?? null,
            ])
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function stepAdditionalContent(): array
    {
        return [
            ...OutputItems::replayContent($this->state->outputItems(), $this->state->finishReason() ?? FinishReason::Stop),
            ...Arr::whereNotNull(['refusal' => $this->state->refusal()]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function isTerminalEvent(array $data): bool
    {
        return in_array(data_get($data, 'type'), [
            'response.completed',
            'response.incomplete',
            'response.failed',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Generator<StreamEvent>
     */
    protected function handleTerminalEvent(array $data): Generator
    {
        /** @var array<string, mixed> $response */
        $response = data_get($data, 'response') ?? [];

        $this->state->withTerminalResponse($response);
        $this->state->withMetadata(['response_id' => data_get($response, 'id')]);
        $this->state->withFinishReason($this->mapTerminalFinishReason($response));

        if (is_array(data_get($response, 'usage'))) {
            $stepUsage = $this->mapUsage($response);

            $this->state->withStepUsage($stepUsage);
            $this->state->addUsage($stepUsage);
        }

        if (data_get($data, 'type') === 'response.failed') {
            yield new ErrorEvent(
                id: EventID::generate(),
                timestamp: time(),
                errorType: (string) data_get($response, 'error.code', 'response_failed'),
                message: (string) data_get($response, 'error.message', 'The response failed.'),
                recoverable: false,
                metadata: Arr::whereNotNull(['response_id' => data_get($response, 'id')]),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapTerminalFinishReason(array $response): FinishReason
    {
        $finishReason = $this->mapFinishReason($response);

        if (data_get($response, 'status') === 'completed' && $this->state->refusal() !== null) {
            return FinishReason::Refusal;
        }

        return $finishReason;
    }

    protected function shouldExecuteToolCalls(): bool
    {
        return $this->state->finishReason() === FinishReason::ToolCalls
            && $this->state->completedToolCalls() !== [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function captureOutputItem(array $data): void
    {
        if (data_get($data, 'type') !== 'response.output_item.done') {
            return;
        }

        $item = data_get($data, 'item');

        if (! OutputItems::isReplayable($item)) {
            return;
        }

        /** @var array<string, mixed> $item */
        $this->state->addOutputItem(
            (int) data_get($data, 'output_index', count($this->state->outputItems())),
            OutputItems::normalize($item),
        );

        if ($item['type'] === 'function_call') {
            $this->markToolCallComplete($item);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function markToolCallComplete(array $item): void
    {
        if (($item['status'] ?? 'completed') !== 'completed') {
            return;
        }

        foreach ($this->state->toolCalls() as $index => $call) {
            if (($call['id'] ?? null) === ($item['id'] ?? null)) {
                $this->state->updateToolCall($index, Arr::whereNotNull([
                    'arguments' => $item['arguments'] ?? null,
                    'complete' => true,
                ]));

                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function isRefusalEvent(array $data): bool
    {
        return in_array(data_get($data, 'type'), [
            'response.refusal.delta',
            'response.refusal.done',
        ], true);
    }

    /**
     * Deltas accumulate per content part; the done event replaces them, so the
     * final refusal text is never counted twice.
     *
     * @param  array<string, mixed>  $data
     */
    protected function captureRefusal(array $data): void
    {
        $partKey = sprintf('%s:%s', data_get($data, 'item_id', ''), data_get($data, 'content_index', 0));

        if (data_get($data, 'type') === 'response.refusal.done') {
            $this->state->withRefusal($partKey, (string) data_get($data, 'refusal', ''));

            return;
        }

        $this->state->appendRefusal($partKey, (string) data_get($data, 'delta', ''));
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

        if ($line === '[DONE]') {
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
    protected function extractToolCalls(array $data, array $reasoningItems = []): ?ToolCallDeltaEvent
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

            return null;
        }

        if ($type === 'response.function_call_arguments.delta') {
            $callId = data_get($data, 'item_id');
            $delta = data_get($data, 'delta', '');

            $toolCalls = $this->state->toolCalls();
            foreach ($toolCalls as $index => $call) {
                if (($call['id'] ?? null) === $callId) {
                    $currentArgs = $call['arguments'] ?? '';
                    $this->state->updateToolCall($index, ['arguments' => $currentArgs.$delta]);

                    return new ToolCallDeltaEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolId: $call['id'],
                        toolName: $call['name'],
                        delta: $delta,
                        messageId: $this->state->messageId()
                    );
                }
            }
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

        return null;
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(Request $request, int $depth): Generator
    {
        $mappedToolCalls = $this->mapToolCalls($this->state->completedToolCalls());
        $toolResults = [];
        yield from $this->callToolsAndYieldEvents($request->tools(), $mappedToolCalls, $this->state->messageId(), $toolResults);

        // Emit step finish after tool calls
        $this->state->markStepFinished();
        $stepAdditionalContent = $this->stepAdditionalContent();

        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time(),
            usage: $this->state->stepUsage(),
            additionalContent: $stepAdditionalContent,
            finishReason: FinishReason::ToolCalls,
        );

        $request->addMessage(new AssistantMessage(
            content: $this->state->currentText(),
            toolCalls: $mappedToolCalls,
            additionalContent: $stepAdditionalContent,
        ));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        $depth++;

        if ($depth < $request->maxSteps()) {
            $nextResponse = $this->sendRequest($request);

            yield from $this->processStream($nextResponse, $request, $depth);
        } else {
            yield $this->emitStreamEndEvent();
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
                reasoningSummary: data_get($toolCall, 'reasoning_summary') ?? [],
            ))
            ->values()
            ->all();
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
        /** @var Response $response */
        $response = $this
            ->client
            ->withOptions(['stream' => true])
            ->post(
                'responses',
                array_merge([
                    'stream' => true,
                    'model' => $request->model(),
                    'input' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                ], Arr::whereNotNull([
                    'max_output_tokens' => $request->maxTokens(),
                    'temperature' => $request->temperature(),
                    'top_p' => $request->topP(),
                    'metadata' => $request->providerOptions('metadata'),
                    'tools' => $this->buildTools($request),
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                    'parallel_tool_calls' => $request->providerOptions('parallel_tool_calls'),
                    'previous_response_id' => $request->providerOptions('previous_response_id'),
                    'service_tier' => $request->providerOptions('service_tier'),
                    'text' => $request->providerOptions('text_verbosity') ? [
                        'verbosity' => $request->providerOptions('text_verbosity'),
                    ] : null,
                    'truncation' => $request->providerOptions('truncation'),
                    'reasoning' => $request->providerOptions('reasoning'),
                    'store' => $request->providerOptions('store'),
                ]))
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
}
