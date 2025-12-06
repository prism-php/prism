<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Mistral\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Mistral\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\Mistral\Concerns\ValidatesResponse;
use Prism\Prism\Providers\Mistral\Maps\MessageMap;
use Prism\Prism\Providers\Mistral\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Mistral\Maps\ToolMap;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
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
    use CallsTools, MapsFinishReason, ProcessRateLimits, ValidatesResponse;

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
        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        if ($depth >= $request->maxSteps()) {
            throw new PrismException('Maximum tool call chain depth exceeded');
        }

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
                $this->state->withMessageId(EventID::generate())->markStreamStarted();

                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: $request->model(),
                    provider: 'mistral'
                );
            }

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                if ($this->mapFinishReason($data) === FinishReason::ToolCalls) {
                    if ($this->state->hasTextStarted() && $text !== '') {
                        yield new TextCompleteEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            messageId: $this->state->messageId()
                        );
                    }

                    yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);

                    return;
                }

                continue;
            }

            if ($this->mapFinishReason($data) === FinishReason::ToolCalls) {
                if ($this->state->hasTextStarted() && $text !== '') {
                    yield new TextCompleteEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId()
                    );
                }

                yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);

                return;
            }

            $content = data_get($data, 'choices.0.delta.content', '') ?? '';

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
     * @return array<string, mixed>|null Parsed JSON data or null if the line should be skipped
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $line = trim(substr($line, strlen('data:')));

        if ($line === '' || $line === '[DONE]') {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('Mistral', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        $parts = data_get($data, 'choices.0.delta.tool_calls', []);

        foreach ($parts as $index => $part) {
            if (isset($part['function'])) {
                $toolCalls[$index]['id'] = data_get($part, 'id', Str::random(8));
                $toolCalls[$index]['name'] = data_get($part, 'function.name');
                $toolCalls[$index]['arguments'] = data_get($part, 'function.arguments', '');
            }
        }

        return $toolCalls;
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

        $this->state->resetTextState();
        $this->state->withMessageId(EventID::generate());

        $nextResponse = $this->sendRequest($request);
        yield from $this->processStream($nextResponse, $request, $depth + 1);
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
                $arguments = data_get($toolCall, 'arguments', '');

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
                    data_get($toolCall, 'name'),
                    $arguments,
                );
            })
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        $parts = data_get($data, 'choices.0.delta.tool_calls', []);

        foreach ($parts as $part) {
            if (isset($part['function'])) {
                return true;
            }
        }

        return false;
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this
            ->client
            ->withOptions(['stream' => true])
            ->post('chat/completions',
                array_merge([
                    'stream' => true,
                    'model' => $request->model(),
                    'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                    'max_tokens' => $request->maxTokens(),
                ], Arr::whereNotNull([
                    'temperature' => $request->temperature(),
                    'top_p' => $request->topP(),
                    'tools' => ToolMap::map($request->tools()),
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
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
            completionTokens: (int) data_get($usage, 'completion_tokens', 0)
        );
    }
}
