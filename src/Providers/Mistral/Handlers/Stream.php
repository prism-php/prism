<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Handlers;

use Generator;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Providers\Mistral\Concerns\HandleResponseError;
use Prism\Prism\Providers\Mistral\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Mistral\Concerns\ProcessRateLimits;
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
    use CallsTools, HandleResponseError, MapsFinishReason, ProcessRateLimits;

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

            // Skip empty data
            if ($data === null) {
                continue;
            }

            // Process tool calls
            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                continue;
            }

            // Handle content
            $content = data_get($data, 'choices.0.delta.content', '');
            $text .= $content;

            $finishReason = data_get($data, 'done', false) ? FinishReason::Stop : FinishReason::Unknown;

            yield new Chunk(
                text: $content,
                finishReason: $finishReason !== FinishReason::Unknown ? $finishReason : null
            );
        }

        // Check if there are tool calls
        if ($toolCalls !== []) {
            yield from $this->handleToolCalls($text, $toolCalls);
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
            throw new PrismChunkDecodeException('Mistral', $e);
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
     * @return Generator<Chunk>
     */
    protected function handleToolCalls(string $text, array $toolCalls): Generator
    {
        // Convert collected tool call data to ToolCall objects
        $toolCalls = $this->mapToolCalls($toolCalls);

        // Call the tools and get results
        $toolResults = $this->callTools($this->request->tools(), $toolCalls);

        $this->request->addMessage(new AssistantMessage($text, $toolCalls));
        $this->request->addMessage(new ToolResultMessage($toolResults));

        // Yield the tool call chunk
        yield new Chunk(
            text: '',
            toolCalls: $toolCalls,
            toolResults: $toolResults,
        );

        $this->step++;

        if ($this->shouldContinue()) {
            // Continue the conversation with tool results
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
        $parts = data_get($data, 'choices.0.delta.tool_calls', []);

        foreach ($parts as $part) {
            if (isset($part['function'])) {
                return true;
            }
        }

        return false;
    }

    protected function sendRequest(): void
    {
        $this->httpResponse = $this
            ->client
            ->withOptions(['stream' => true])
            ->post(
                'chat/completions',
                static::buildHttpRequestPayload($this->request)
            );
    }
}
