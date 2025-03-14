<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\OpenAI\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessesRateLimits;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolMap;
use Prism\Prism\Stream\Chunk;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream
{
    use CallsTools,
        MapsFinishReason,
        ProcessesRateLimits;

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
        // Prevent infinite recursion with tool calls
        if ($depth >= $request->maxSteps()) {
            throw new PrismException('Maximum tool call chain depth exceeded');
        }
        $text = '';
        $toolCalls = [];

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            // Skip empty data or DONE markers
            if ($data === null) {
                continue;
            }

            // Process tool calls
            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);

                return;
            }

            // Process regular content
            $content = data_get($data, 'delta', '') ?? '';
            $text .= $content;

            $finishReason = $this->mapFinishReason(data_get($data, 'response') ?? []);

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

        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $line = trim(substr($line, strlen('data: ')));

        if (Str::contains($line, 'DONE')) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismChunkDecodeException('OpenAI', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        $toolCalls[] = [
            'name' => data_get($data, 'item.name'),
            'id' => data_get($data, 'item.id') ?? '',
            'call_id' => data_get($data, 'item.call_id') ?? '',
            'arguments' => data_get($data, 'item.arguments') ?? '',
        ];

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
        // Convert collected tool call data to ToolCall objects
        $toolCalls = $this->mapToolCalls($toolCalls);

        // Call the tools and get results
        $toolResults = $this->callTools($request->tools(), $toolCalls);

        $request->addMessage(new AssistantMessage($text, $toolCalls));
        $request->addMessage(new ToolResultMessage($toolResults));

        // Yield the tool call chunk
        yield new Chunk(
            text: '',
            toolCalls: $toolCalls,
            toolResults: $toolResults,
        );

        // Continue the conversation with tool results
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
            ->map(fn ($toolCall): ToolCall => new ToolCall(
                data_get($toolCall, 'id'),
                data_get($toolCall, 'name'),
                data_get($toolCall, 'arguments'),
                data_get($toolCall, 'call_id'),
            ))
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return data_get($data, 'item.type') === 'function_call' && data_get($data, 'type') === 'response.output_item.done';
    }

    protected function sendRequest(Request $request): Response
    {
        try {
            return $this
                ->client
                ->withOptions(['stream' => true])
                ->throw()
                ->post(
                    'responses',
                    array_merge([
                        'stream' => true,
                        'model' => $request->model(),
                        'input' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                        'max_output_tokens' => $request->maxTokens(),
                    ], array_filter([
                        'temperature' => $request->temperature(),
                        'top_p' => $request->topP(),
                        'tools' => ToolMap::map($request->tools()),
                        'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                    ]))
                );
        } catch (Throwable $e) {
            if ($e instanceof RequestException && $e->response->getStatusCode() === 429) {
                throw new PrismRateLimitedException($this->processRateLimits($e->response));
            }

            throw PrismException::providerRequestError($request->model(), $e);
        }
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
