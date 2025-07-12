<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Groq\Handlers;

use Generator;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\Groq\Concerns\HandleResponseError;
use Prism\Prism\Providers\Groq\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\Groq\Maps\FinishReasonMap;
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
    use CallsTools, HandleResponseError, ProcessRateLimits;

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

            if ($this->hasError($data)) {
                $this->handleErrors($data);
            }

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                continue;
            }

            $content = data_get($data, 'choices.0.delta.content', '') ?? '';
            $text .= $content;

            $finishReason = $this->mapFinishReason($data);

            yield new Chunk(
                text: $content,
                finishReason: $finishReason !== FinishReason::Unknown ? $finishReason : null
            );
        }

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
            throw new PrismChunkDecodeException('Groq', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        foreach (data_get($data, 'choices.0.delta.tool_calls', []) as $index => $toolCall) {
            if ($name = data_get($toolCall, 'function.name')) {
                $toolCalls[$index]['name'] = $name;
                $toolCalls[$index]['arguments'] = '';
                $toolCalls[$index]['id'] = data_get($toolCall, 'id');
            }

            $arguments = data_get($toolCall, 'function.arguments');

            if (! is_null($arguments)) {
                $toolCalls[$index]['arguments'] .= $arguments;
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
     * Convert raw tool call data to ToolCall objects.
     *
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return collect($toolCalls)
            ->map(fn ($toolCall): ToolCall => new ToolCall(
                data_get($toolCall, 'id'),
                data_get($toolCall, 'name'),
                data_get($toolCall, 'arguments'),
            ))
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return (bool) data_get($data, 'choices.0.delta.tool_calls');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasError(array $data): bool
    {
        return data_get($data, 'error') !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        return FinishReasonMap::map(data_get($data, 'choices.0.finish_reason'));
    }

    protected function sendRequest(): void
    {
        $this->httpResponse = $this
            ->client
            ->withOptions(['stream' => true])
            ->throw()
            ->post(
                'chat/completions',
                static::buildHttpRequestPayload($this->request)
            );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleErrors(array $data): void
    {
        $error = data_get($data, 'error', []);
        $type = data_get($error, 'type', 'unknown_error');
        $message = data_get($error, 'message', 'No error message provided');

        if ($type === 'rate_limit_exceeded') {
            throw new PrismRateLimitedException([]);
        }

        throw new PrismException(sprintf(
            'Sending to model %s failed. Type: %s. Message: %s',
            $this->request->model(),
            $type,
            $message
        ));
    }
}
