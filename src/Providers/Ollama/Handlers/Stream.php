<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\Handlers;

use Generator;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Providers\Ollama\Concerns\MapsFinishReason;
use Prism\Prism\Providers\StreamHandler;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream extends StreamHandler
{
    use CallsTools, MapsFinishReason;

    public static function buildHttpRequestPayload(Request $request): array
    {
        return [
            ...Text::buildHttpRequestPayload($request),
            'stream' => true,
        ];
    }

    /**
     * @return Generator<Chunk>
     */
    protected function processStream(): Generator
    {
        $text = '';
        $toolCalls = [];

        while (! $this->httpResponse->getBody()->eof()) {
            $data = $this->parseNextDataLine($this->httpResponse->getBody());

            if ($data === null) {
                continue;
            }

            // Accumulate tool calls if present
            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                continue;
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

        // Handle tool call completion when stream is done
        if ($toolCalls !== []) {
            yield from $this->handleToolCalls($text, $toolCalls);
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
    protected function handleToolCalls(string $text, array $toolCalls): Generator
    {
        $toolCalls = $this->mapToolCalls($toolCalls);

        yield new Chunk(
            text: '',
            toolCalls: $toolCalls,
            chunkType: ChunkType::ToolCall,
        );

        $toolResults = $this->callTools($this->request->tools(), $toolCalls);

        yield new Chunk(
            text: '',
            toolResults: $toolResults,
            chunkType: ChunkType::ToolResult,
        );

        $this->request->addMessage(new AssistantMessage($text, $toolCalls));
        $this->request->addMessage(new ToolResultMessage($toolResults));

        $this->step++;

        if ($this->shouldContinue()) {
            $this->sendRequest();
            yield from $this->processStream();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return (bool) data_get($data, 'message.tool_calls');
    }

    protected function sendRequest(): void
    {
        $this->httpResponse = $this
            ->client
            ->withOptions(['stream' => true])
            ->post(
                'api/chat',
                static::buildHttpRequestPayload($this->request)
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
