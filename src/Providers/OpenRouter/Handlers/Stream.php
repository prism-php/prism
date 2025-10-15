<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\OpenRouter\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenRouter\Concerns\ValidatesResponses;
use Prism\Prism\Providers\OpenRouter\Maps\MessageMap;
use Prism\Prism\Providers\OpenRouter\Maps\ToolChoiceMap;
use Prism\Prism\Providers\OpenRouter\Maps\ToolMap;
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
    use CallsTools;
    use MapsFinishReason;
    use ValidatesResponses;

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
        if ($depth === 0) {
            $this->state->reset();
        }

        $text = '';
        $toolCalls = [];

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            if ($this->state->shouldEmitStreamStart()) {
                $this->state
                    ->withMessageId(EventID::generate('msg'))
                    ->markStreamStarted();

                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: $data['model'] ?? $request->model(),
                    provider: 'openrouter'
                );
            }

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                $finishReason = data_get($data, 'choices.0.finish_reason');
                if ($finishReason !== null && $this->mapFinishReason($data) === FinishReason::ToolCalls) {
                    if ($this->state->hasTextStarted() && $text !== '') {
                        yield new TextCompleteEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            messageId: $this->state->messageId()
                        );
                    }

                    if ($this->state->hasThinkingStarted() && $this->state->currentThinking() !== '') {
                        yield new ThinkingCompleteEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            reasoningId: $this->state->reasoningId()
                        );
                    }

                    yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);

                    return;
                }

                continue;
            }

            $finishReasonValue = data_get($data, 'choices.0.finish_reason');
            if ($finishReasonValue !== null && $this->mapFinishReason($data) === FinishReason::ToolCalls) {
                if ($this->state->hasTextStarted() && $text !== '') {
                    yield new TextCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId()
                    );
                }

                if ($this->state->hasThinkingStarted() && $this->state->currentThinking() !== '') {
                    yield new ThinkingCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        reasoningId: $this->state->reasoningId()
                    );
                }

                yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);

                return;
            }

            if ($this->hasReasoningDelta($data)) {
                $reasoningDelta = $this->extractReasoningDelta($data);

                if ($reasoningDelta !== '') {
                    if ($this->state->shouldEmitThinkingStart()) {
                        $this->state
                            ->withReasoningId(EventID::generate('reasoning'))
                            ->markThinkingStarted();

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

            $content = $this->extractContentDelta($data);
            if ($content !== '') {
                if ($this->state->shouldEmitTextStart()) {
                    $this->state->markTextStarted();

                    yield new TextStartEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId()
                    );
                }

                $text .= $content;

                yield new TextDeltaEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $content,
                    messageId: $this->state->messageId()
                );
            }

            $rawFinishReason = data_get($data, 'choices.0.finish_reason');
            if ($rawFinishReason !== null) {
                $finishReason = $this->mapFinishReason($data);

                if ($this->state->hasTextStarted() && $text !== '') {
                    yield new TextCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId()
                    );
                }

                if ($this->state->hasThinkingStarted() && $this->state->currentThinking() !== '') {
                    yield new ThinkingCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        reasoningId: $this->state->reasoningId()
                    );
                }

                $usage = $this->extractUsage($data);

                yield new StreamEndEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    finishReason: $finishReason,
                    usage: $usage
                );
            }
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

        if (Str::contains($line, '[DONE]')) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('OpenRouter', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return isset($data['choices'][0]['delta']['tool_calls']);
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
            $index = $deltaToolCall['index'];

            if (isset($deltaToolCall['id'])) {
                $toolCalls[$index]['id'] = $deltaToolCall['id'];
            }

            if (isset($deltaToolCall['type'])) {
                $toolCalls[$index]['type'] = $deltaToolCall['type'];
            }

            if (isset($deltaToolCall['function'])) {
                if (isset($deltaToolCall['function']['name'])) {
                    $toolCalls[$index]['function']['name'] = $deltaToolCall['function']['name'];
                }

                if (isset($deltaToolCall['function']['arguments'])) {
                    $toolCalls[$index]['function']['arguments'] =
                        ($toolCalls[$index]['function']['arguments'] ?? '').
                        $deltaToolCall['function']['arguments'];
                }
            }
        }

        return $toolCalls;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasReasoningDelta(array $data): bool
    {
        return isset($data['choices'][0]['delta']['reasoning']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractReasoningDelta(array $data): string
    {
        return data_get($data, 'choices.0.delta.reasoning', '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractContentDelta(array $data): string
    {
        return data_get($data, 'choices.0.delta.content', '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        $finishReason = data_get($data, 'choices.0.finish_reason');

        if ($finishReason === null) {
            return FinishReason::Unknown;
        }

        return $this->mapFinishReason($data);
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(
        Request $request,
        string $text,
        array $toolCalls,
        int $depth
    ): Generator {
        $mappedToolCalls = $this->mapToolCalls($toolCalls);

        foreach ($mappedToolCalls as $toolCall) {
            yield new ToolCallEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolCall: $toolCall,
                messageId: $this->state->messageId()
            );
        }

        $toolResults = $this->callTools($request->tools(), $mappedToolCalls);

        foreach ($toolResults as $result) {
            yield new ToolResultEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolResult: $result,
                messageId: $this->state->messageId()
            );
        }

        $request->addMessage(new AssistantMessage($text, $mappedToolCalls));
        $request->addMessage(new ToolResultMessage($toolResults));

        $this->state
            ->resetTextState()
            ->withMessageId(EventID::generate('msg'));

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
            ->map(function ($toolCall): ToolCall {
                $arguments = data_get($toolCall, 'function.arguments', '');

                if (is_string($arguments) && $arguments !== '') {
                    try {
                        $parsedArguments = json_decode($arguments, true, flags: JSON_THROW_ON_ERROR);
                        $arguments = $parsedArguments;
                    } catch (Throwable) {
                        $arguments = ['raw' => $arguments];
                    }
                }

                return new ToolCall(
                    data_get($toolCall, 'id'),
                    data_get($toolCall, 'function.name'),
                    $arguments,
                );
            })
            ->toArray();
    }

    protected function sendRequest(Request $request): Response
    {
        return $this
            ->client
            ->withOptions(['stream' => true])
            ->post(
                'chat/completions',
                array_merge([
                    'stream' => true,
                    'model' => $request->model(),
                    'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                    'max_tokens' => $request->maxTokens(),
                ], Arr::whereNotNull([
                    'temperature' => $request->temperature(),
                    'top_p' => $request->topP(),
                    'reasoning' => $request->providerOptions('reasoning') ?? null,
                    'tools' => ToolMap::map($request->tools()),
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                    'stream_options' => ['include_usage' => true],
                    'provider' => $request->providerOptions('provider') ?? null,
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

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data): ?Usage
    {
        $usage = data_get($data, 'usage');

        if (! $usage) {
            return null;
        }

        return new Usage(
            promptTokens: (int) data_get($usage, 'prompt_tokens', 0),
            completionTokens: (int) data_get($usage, 'completion_tokens', 0),
            cacheReadInputTokens: (int) data_get($usage, 'prompt_tokens_details.cached_tokens', 0),
            thoughtTokens: (int) data_get($usage, 'completion_tokens_details.reasoning_tokens', 0)
        );
    }
}
