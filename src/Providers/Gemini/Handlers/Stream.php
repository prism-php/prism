<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Generator;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Providers\Gemini\Maps\FinishReasonMap;
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
    use CallsTools;

    public static function buildHttpRequestPayload(Request $request): array
    {
        return Text::buildHttpRequestPayload($request);
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
            $content = data_get($data, 'candidates.0.content.parts.0.text') ?? '';
            $text .= $content;

            $finishReason = $this->mapFinishReason($data);

            $chunk = new Chunk(
                text: $content,
                finishReason: $finishReason !== FinishReason::Unknown ? $finishReason : null
            );

            yield $chunk;
        }

        // Check if this is the final part of the tool calls
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
            throw new PrismChunkDecodeException('Gemini', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        $parts = data_get($data, 'candidates.0.content.parts', []);

        foreach ($parts as $index => $part) {
            if (isset($part['functionCall'])) {
                $toolCalls[$index]['name'] = data_get($part, 'functionCall.name');
                $toolCalls[$index]['arguments'] = data_get($part, 'functionCall.args', '');
            }
        }

        return $toolCalls;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return Generator<Chunk>
     */
    protected function handleToolCalls(
        string $text,
        array $toolCalls,
    ): Generator {
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

        // Continue the conversation with tool results
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
                (string) array_key_exists('id', $toolCall) !== '' && (string) array_key_exists('id', $toolCall) !== '0' ? $toolCall['id'] : 'gm-'.Str::random(20),
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
        $parts = data_get($data, 'candidates.0.content.parts', []);

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        $finishReason = data_get($data, 'candidates.0.finishReason');

        if (! $finishReason) {
            return FinishReason::Unknown;
        }

        $isToolCall = $this->hasToolCalls($data);

        return FinishReasonMap::map($finishReason, $isToolCall);
    }

    protected function sendRequest(): void
    {
        $this->httpResponse = $this->client
            ->withOptions(['stream' => true])
            ->post(
                "{$this->request->model()}:streamGenerateContent?alt=sse",
                static::buildHttpRequestPayload($this->request)
            );
    }
}
