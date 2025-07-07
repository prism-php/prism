<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Providers\OpenRouter\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenRouter\Concerns\ValidatesResponses;
use Prism\Prism\Providers\OpenRouter\Maps\MessageMap;
use Prism\Prism\Providers\OpenRouter\Maps\ToolChoiceMap;
use Prism\Prism\Providers\OpenRouter\Maps\ToolMap;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream
{
    use CallsTools;
    use MapsFinishReason;
    use ValidatesResponses;

    public function __construct(protected PendingRequest $client) {}

    /**
     * @return Generator<Chunk>
     */
    public function handle(Request $request): Generator
    {
        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * @return Generator<Chunk>
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        $text = '';
        $toolCalls = [];
        $finishReason = null;
        $usage = null;
        $meta = null;

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            if (isset($data['id']) && ! $meta instanceof \Prism\Prism\ValueObjects\Meta) {
                $meta = new Meta(
                    id: $data['id'],
                    model: $data['model'] ?? null,
                );

                yield new Chunk(
                    text: '',
                    finishReason: null,
                    meta: $meta,
                    chunkType: ChunkType::Meta,
                );
            }

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                continue;
            }

            if ($this->hasReasoningDelta($data)) {
                $reasoningDelta = $this->extractReasoningDelta($data);

                if ($reasoningDelta !== '') {
                    yield new Chunk(
                        text: $reasoningDelta,
                        finishReason: null,
                        chunkType: ChunkType::Thinking
                    );
                }

                continue;
            }

            $content = $this->extractContentDelta($data);
            if ($content !== '') {
                $text .= $content;

                yield new Chunk(
                    text: $content,
                    finishReason: null
                );
            }

            $currentFinishReason = $this->extractFinishReason($data);
            if ($currentFinishReason !== FinishReason::Unknown) {
                $finishReason = $currentFinishReason;
            }

            if (isset($data['usage'])) {
                $usage = new Usage(
                    promptTokens: data_get($data, 'usage.prompt_tokens'),
                    completionTokens: data_get($data, 'usage.completion_tokens'),
                    cacheReadInputTokens: data_get($data, 'usage.prompt_tokens_details.cached_tokens'),
                    thoughtTokens: data_get($data, 'usage.completion_tokens_details.reasoning_tokens')
                );
            }

            if (isset($data['choices'][0]['finish_reason']) && $data['choices'][0]['finish_reason'] !== null) {
                if ($usage instanceof \Prism\Prism\ValueObjects\Usage) {
                    yield new Chunk(
                        text: '',
                        usage: $usage,
                        chunkType: ChunkType::Meta,
                    );
                }

                if ($finishReason instanceof \Prism\Prism\Enums\FinishReason) {
                    yield new Chunk(
                        text: '',
                        finishReason: $finishReason,
                        chunkType: ChunkType::Meta,
                    );
                }

                break;
            }
        }

        if ($toolCalls !== []) {
            yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);
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
            throw new PrismChunkDecodeException('OpenRouter', $e);
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
     * @return Generator<Chunk>
     */
    protected function handleToolCalls(
        Request $request,
        string $text,
        array $toolCalls,
        int $depth
    ): Generator {
        $toolCalls = $this->mapToolCalls($toolCalls);

        yield new Chunk(
            text: '',
            toolCalls: $toolCalls,
            chunkType: ChunkType::ToolCall,
        );

        $toolResults = $this->callTools($request->tools(), $toolCalls);

        yield new Chunk(
            text: '',
            toolResults: $toolResults,
            chunkType: ChunkType::ToolResult,
        );

        $request->addMessage(new AssistantMessage($text, $toolCalls));
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
        return array_map(function ($toolCall): ToolCall {
            $arguments = data_get($toolCall, 'function.arguments', '');

            // Try to decode JSON arguments
            if (is_string($arguments) && $arguments !== '') {
                try {
                    $arguments = json_decode($arguments, true, flags: JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    // Keep as string if JSON decode fails
                }
            }

            return new ToolCall(
                id: data_get($toolCall, 'id'),
                name: data_get($toolCall, 'function.name'),
                arguments: $arguments,
            );
        }, $toolCalls);
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
                    'tools' => ToolMap::map($request->tools()),
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                    'stream_options' => ['include_usage' => true],
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
