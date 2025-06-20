<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Ollama\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Ollama\Maps\MessageMap;
use Prism\Prism\Providers\Ollama\Maps\ToolMap;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream
{
    use CallsTools, MapsFinishReason;

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

        if ($depth >= $request->maxSteps()) {
            throw new PrismException('Maximum tool call chain depth exceeded');
        }

        $text = '';
        $toolCalls = [];

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            // Accumulate tool calls if present
            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                continue;
            }

            // Handle tool call completion when stream is done
            if ((bool) data_get($data, 'done', false) && $toolCalls !== []) {
                yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);

                return;
            }

            $content = data_get($data, 'message.content', '') ?? '';
            $text .= $content;

            $finishReason = (bool) data_get($data, 'done', false)
                ? FinishReason::Stop
                : FinishReason::Unknown;

            yield new Chunk(
                text: $content,
                finishReason: $finishReason !== FinishReason::Unknown ? $finishReason : null
            );
        }
    }

    /**
     * @return array<string, mixed>|null Parsed JSON data or null if line should be skipped
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (in_array(trim($line), ['', '0'], true)) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismChunkDecodeException('Ollama', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        foreach (data_get($data, 'message.tool_calls', []) as $index => $toolCall) {
            if ($name = data_get($toolCall, 'function.name')) {
                $toolCalls[$index]['name'] = $name;
                $toolCalls[$index]['arguments'] = '';
                $toolCalls[$index]['id'] = data_get($toolCall, 'id');
            }

            if ($arguments = data_get($toolCall, 'function.arguments')) {

                $argumentValue = is_array($arguments) ? json_encode($arguments) : $arguments;
                $toolCalls[$index]['arguments'] .= $argumentValue;
            }
        }

        return $toolCalls;
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

        $nextResponse = $this->sendRequest($request);
        yield from $this->processStream($nextResponse, $request, $depth + 1);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return (bool) data_get($data, 'message.tool_calls');
    }

    protected function sendRequest(Request $request): Response
    {
        if (count($request->systemPrompts()) > 1) {
            throw new PrismException('Ollama does not support multiple system prompts using withSystemPrompt / withSystemPrompts. However, you can provide additional system prompts by including SystemMessages in with withMessages.');
        }

        return $this
            ->client
            ->withOptions(['stream' => true])
            ->post('api/chat', [
                'model' => $request->model(),
                'system' => data_get($request->systemPrompts(), '0.content', ''),
                'messages' => (new MessageMap($request->messages()))->map(),
                'tools' => ToolMap::map($request->tools()),
                'stream' => true,
                'options' => Arr::whereNotNull(array_merge([
                    'temperature' => $request->temperature(),
                    'num_predict' => $request->maxTokens() ?? 2048,
                    'top_p' => $request->topP(),
                ], $request->providerOptions())),
            ]);
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
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'id') ?? '',
            name: data_get($toolCall, 'name') ?? '',
            arguments: data_get($toolCall, 'arguments'),
        ), $toolCalls);
    }
}
