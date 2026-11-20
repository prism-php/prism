<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Z\Handlers;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use JsonException;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Concerns\TracksStreamFailureUsage;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Z\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Z\Concerns\MapsUsage;
use Prism\Prism\Providers\Z\Maps\MessageMap;
use Prism\Prism\Providers\Z\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Z\Maps\ToolMap;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\StreamFailureUsageTracker;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Streaming\ToolCallId;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;

class Stream
{
    use CallsTools, MapsFinishReason, MapsUsage;
    use TracksStreamFailureUsage;

    protected StreamState $state;

    public function __construct(protected PendingRequest $client)
    {
        $this->state = new StreamState;
    }

    /**
     * @throws PrismStreamDecodeException
     * @throws PrismException
     * @throws ConnectionException
     */
    public function handle(Request $request): Generator
    {
        $this->streamFailureTracker = null;

        yield from $this->resolveToolApprovalsAndYieldEvents($request, EventID::generate());

        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * @throws PrismStreamDecodeException
     * @throws PrismException
     * @throws ConnectionException
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        $this->streamFailureTracker = new StreamFailureUsageTracker('z', $depth);

        if ($depth >= $request->maxSteps()) {
            throw (new PrismException('Maximum tool call chain depth exceeded'))->withStreamFailureUsage($this->streamFailureUsage());
        }

        $this->state->reset()->withMessageId(EventID::generate());

        $text = '';
        $toolCalls = [];
        $stepFinishReason = null;
        $usageSnapshot = null;

        while (! (yield from $this->readStreamValue(fn (): bool => $response->getBody()->eof()))) {
            $data = yield from $this->readStreamValue(fn (): ?array => $this->parseNextDataLine($response->getBody()));

            if ($data === null) {
                continue;
            }

            // Usage is read from WHICHEVER chunk carries it, before any branch
            // below can skip it (fork #60): on a tool-calling turn Z.ai puts it
            // on the `finish_reason: "tool_calls"` chunk. KEPT, NOT SUMMED: an
            // OpenAI-compatible server behind a configurable `Z_URL` may report
            // running totals on several chunks, so the latest report is the
            // response's total, recorded once when the step ends.
            $usageSnapshot = $this->extractUsage($data) ?? $usageSnapshot;

            if ($this->state->shouldEmitStreamStart()) {
                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: $request->model(),
                    provider: Provider::Z->value,
                );

                $this->state->markStreamStarted();
            }

            if ($this->state->shouldEmitStepStart()) {
                $this->state->markStepStarted();

                yield new StepStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                );
            }

            $content = $this->extractContentDelta($data);

            if ($content !== '') {
                if ($this->state->shouldEmitTextStart()) {
                    $this->state->markTextStarted();

                    yield new TextStartEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId(),
                    );
                }

                $text .= $content;

                yield new TextDeltaEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $content,
                    messageId: $this->state->messageId(),
                );
            }

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);
            }

            $rawFinishReason = data_get($data, 'choices.0.finish_reason');

            if ($rawFinishReason !== null) {
                $finishReason = $this->mapFinishReason($data);

                yield from $this->completeText();

                $this->state->withFinishReason($finishReason);
                $stepFinishReason = $finishReason;
            }
        }

        yield from $this->completeText();

        $stepUsage = $this->recordStepUsage($usageSnapshot);

        if ($toolCalls !== [] && $stepFinishReason === FinishReason::ToolCalls) {
            yield from $this->handleToolCalls($request, $text, $toolCalls, $depth, $stepUsage);

            return;
        }

        $this->state->withFinishReason($stepFinishReason ?? FinishReason::Stop);
        $this->state->markStepFinished();

        // The step's own usage, null when it reported none (upstream #1043).
        // Advancing the snapshot keeps the fork's HITL finish in CallsTools,
        // which measures its step from the last finished one, from
        // counting this step's usage again.
        $this->state->takeStepUsage();

        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time(),
            usage: $stepUsage,
            finishReason: $this->state->finishReason(),
        );

        yield $this->emitStreamEndEvent();
    }

    /**
     * Closes the text this step started, once, before its tool calls or its
     * StepFinishEvent.
     *
     * @return Generator<StreamEvent>
     */
    protected function completeText(): Generator
    {
        if (! $this->state->hasTextStarted()) {
            return;
        }

        $this->state->markTextCompleted();

        yield new TextCompleteEvent(
            id: EventID::generate(),
            timestamp: time(),
            messageId: $this->state->messageId(),
        );
    }

    protected function emitStreamEndEvent(): StreamEndEvent
    {
        return new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: $this->state->finishReason() ?? FinishReason::Stop,
            usage: $this->state->usage() ?? new Usage(0, 0),
        );
    }

    /**
     * @return null|array<string, mixed>
     *
     * @throws PrismStreamDecodeException
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $line = trim(substr($line, strlen('data: ')));

        if (Str::contains($line, '[DONE]')) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw (new PrismStreamDecodeException(Provider::Z->name, $e))->withStreamFailureUsage($this->streamFailureUsage());
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return ! empty(data_get($data, 'choices.0.delta.tool_calls', []));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        $deltaToolCalls = data_get($data, 'choices.0.delta.tool_calls', []);

        foreach ($deltaToolCalls as $deltaToolCall) {
            $index = data_get($deltaToolCall, 'index', 0);

            if (! isset($toolCalls[$index])) {
                $toolCalls[$index] = [
                    'id' => null,
                    'name' => '',
                    'arguments' => '',
                ];
            }

            $toolCalls[$index]['id'] = ToolCallId::remember($toolCalls[$index]['id'], data_get($deltaToolCall, 'id'));

            if ($name = data_get($deltaToolCall, 'function.name')) {
                $toolCalls[$index]['name'] = $name;
            }

            $arguments = data_get($deltaToolCall, 'function.arguments');

            if (is_string($arguments) && $arguments !== '') {
                $toolCalls[$index]['arguments'] .= $arguments;
            }
        }

        return $toolCalls;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractContentDelta(array $data): string
    {
        $delta = data_get($data, 'choices.0.delta', []);

        if (is_array($delta)) {
            $content = data_get($delta, 'content', '');

            return is_string($content) ? $content : '';
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function extractUsage(array $data): ?array
    {
        $usage = data_get($data, 'usage');

        return is_array($usage) && $usage !== [] ? $usage : null;
    }

    /**
     * @param  array<string, mixed>|null  $usageSnapshot
     */
    protected function recordStepUsage(?array $usageSnapshot): ?Usage
    {
        if ($usageSnapshot === null) {
            return null;
        }

        $stepUsage = $this->mapUsage($usageSnapshot);

        $this->state->addUsage($stepUsage);

        return $stepUsage;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     *
     * @throws PrismException
     * @throws PrismStreamDecodeException
     * @throws ConnectionException
     */
    protected function handleToolCalls(Request $request, string $text, array $toolCalls, int $depth, ?Usage $stepUsage = null): Generator
    {
        $mappedToolCalls = $this->mapToolCalls($toolCalls);

        foreach ($mappedToolCalls as $toolCall) {
            yield new ToolCallEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolCall: $toolCall,
                messageId: $this->state->messageId(),
            );
        }

        $toolResults = [];

        $hasPendingToolCalls = false;
        yield from $this->callToolsAndYieldEventsWithPending($request->tools(), $mappedToolCalls, $this->state->messageId(), $toolResults, $hasPendingToolCalls);

        if ($hasPendingToolCalls) {
            // Client-executed or approval-required tool calls: end the stream
            // with FinishReason::ToolCalls so the consumer resolves and resumes.
            $this->state->markStepFinished();
            yield from $this->yieldToolCallsFinishEvents($this->state);

            return;
        }

        $this->state->withFinishReason(FinishReason::ToolCalls);
        $this->state->markStepFinished();

        // The step's own usage, null when it reported none (upstream #1043).
        // Advancing the snapshot keeps the fork's HITL finish in CallsTools,
        // which measures its step from the last finished one, from
        // counting this step's usage again.
        $this->state->takeStepUsage();

        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time(),
            usage: $stepUsage,
            finishReason: FinishReason::ToolCalls,
        );

        $request->addMessage(new AssistantMessage($text, $mappedToolCalls));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        $this->state->resetTextState();
        $this->state->withMessageId(EventID::generate());

        $depth++;

        if ($depth < $request->maxSteps()) {
            $nextResponse = $this->sendRequest($request);
            yield from $this->processStream($nextResponse, $request, $depth);
        } else {
            yield $this->emitStreamEndEvent();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: ToolCallId::resolve(data_get($toolCall, 'id'), 'z'),
            name: data_get($toolCall, 'name'),
            arguments: data_get($toolCall, 'arguments'),
        ), $toolCalls);
    }

    /**
     * @throws ConnectionException
     */
    protected function sendRequest(Request $request): Response
    {
        $this->streamFailureTracker = null;

        return $this
            ->client
            ->withOptions(['stream' => true])
            ->post(
                'chat/completions',
                array_merge([
                    'stream' => true,
                    'model' => $request->model(),
                    'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                ], Arr::whereNotNull([
                    'max_tokens' => $request->maxTokens(),
                    'temperature' => $request->temperature(),
                    'top_p' => $request->topP(),
                    'tools' => ToolMap::map($request->tools()) ?: null,
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
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
