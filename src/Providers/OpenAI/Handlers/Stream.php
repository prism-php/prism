<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\OpenAI\Concerns\BuildsTools;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Maps\FinishReasonMap;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
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
    use BuildsTools;
    use CallsTools;
    use ProcessRateLimits;

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
        $reasoningItems = [];

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            if ($data['type'] === 'error') {
                $this->handleErrors($data, $request);
            }

            if ($data['type'] === 'response.created') {
                yield new Chunk(
                    text: '',
                    finishReason: null,
                    meta: new Meta(
                        id: $data['response']['id'] ?? null,
                        model: $data['response']['model'] ?? null,
                    ),
                    chunkType: ChunkType::Meta,
                );

                continue;
            }

            if ($this->hasReasoningSummaryDelta($data)) {
                $reasoningDelta = $this->extractReasoningSummaryDelta($data);

                if ($reasoningDelta !== '') {
                    yield new Chunk(
                        text: $reasoningDelta,
                        finishReason: null,
                        chunkType: ChunkType::Thinking
                    );
                }

                continue;
            }

            if ($this->hasReasoningItems($data)) {
                $reasoningItems = $this->extractReasoningItems($data, $reasoningItems);

                continue;
            }

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls, $reasoningItems);

                continue;
            }

            $content = $this->extractOutputTextDelta($data);

            $text .= $content;

            $finishReason = $this->mapFinishReason($data);

            yield new Chunk(
                text: $content,
                finishReason: $finishReason !== FinishReason::Unknown ? $finishReason : null
            );

            if (data_get($data, 'type') === 'response.completed') {
                yield new Chunk(
                    text: '',
                    chunkType: ChunkType::Meta,
                    usage: new Usage(
                        promptTokens: data_get($data, 'response.usage.input_tokens'),
                        completionTokens: data_get($data, 'response.usage.output_tokens'),
                        cacheReadInputTokens: data_get($data, 'response.usage.input_tokens_details.cached_tokens'),
                        thoughtTokens: data_get($data, 'response.usage.output_tokens_details.reasoning_tokens')
                    ),
                );
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
     * @param  array<int, array<string, mixed>>  $reasoningItems
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls, array $reasoningItems = []): array
    {
        $type = data_get($data, 'type', '');

        if ($type === 'response.output_item.added' && data_get($data, 'item.type') === 'function_call') {
            $index = (int) data_get($data, 'output_index', count($toolCalls));

            $toolCalls[$index]['id'] = data_get($data, 'item.id');
            $toolCalls[$index]['call_id'] = data_get($data, 'item.call_id');
            $toolCalls[$index]['name'] = data_get($data, 'item.name');
            $toolCalls[$index]['arguments'] = '';

            // Associate with the most recent reasoning item if available
            if ($reasoningItems !== []) {
                $latestReasoning = end($reasoningItems);
                $toolCalls[$index]['reasoning_id'] = $latestReasoning['id'];
                $toolCalls[$index]['reasoning_summary'] = $latestReasoning['summary'] ?? [];
            }

            return $toolCalls;
        }

        if ($type === 'response.function_call_arguments.delta') {
            // continue for now, only needed if we want to support streaming argument chunks
        }

        if ($type === 'response.function_call_arguments.done') {
            $callId = data_get($data, 'item_id');
            $arguments = data_get($data, 'arguments', '');

            foreach ($toolCalls as &$call) {
                if (($call['id'] ?? null) === $callId) {
                    if ($arguments !== '') {
                        $call['arguments'] = $arguments;
                    }
                    break;
                }
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
            ->map(fn ($toolCall): ToolCall => new ToolCall(
                id: data_get($toolCall, 'id'),
                name: data_get($toolCall, 'name'),
                arguments: data_get($toolCall, 'arguments'),
                resultId: data_get($toolCall, 'call_id'),
                reasoningId: data_get($toolCall, 'reasoning_id'),
                reasoningSummary: data_get($toolCall, 'reasoning_summary', []),
            ))
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        $type = data_get($data, 'type', '');

        if (data_get($data, 'item.type') === 'function_call') {
            return true;
        }

        return in_array($type, [
            'response.function_call_arguments.delta',
            'response.function_call_arguments.done',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasReasoningItems(array $data): bool
    {
        $type = data_get($data, 'type', '');

        return $type === 'response.output_item.done' && data_get($data, 'item.type') === 'reasoning';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $reasoningItems
     * @return array<int, array<string, mixed>>
     */
    protected function extractReasoningItems(array $data, array $reasoningItems): array
    {
        if (data_get($data, 'type') === 'response.output_item.done' && data_get($data, 'item.type') === 'reasoning') {
            $index = (int) data_get($data, 'output_index', count($reasoningItems));

            $reasoningItems[$index] = [
                'id' => data_get($data, 'item.id'),
                'summary' => data_get($data, 'item.summary', []),
            ];
        }

        return $reasoningItems;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        $eventType = Str::after(data_get($data, 'type'), 'response.');
        $lastOutputType = data_get($data, 'response.output.{last}.type');

        return FinishReasonMap::map($eventType, $lastOutputType);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasReasoningSummaryDelta(array $data): bool
    {
        $type = data_get($data, 'type', '');

        return $type === 'response.reasoning_summary_text.delta';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractReasoningSummaryDelta(array $data): string
    {
        if (data_get($data, 'type') === 'response.reasoning_summary_text.delta') {
            return (string) data_get($data, 'delta', '');
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractOutputTextDelta(array $data): string
    {
        if (data_get($data, 'type') === 'response.output_text.delta') {
            return (string) data_get($data, 'delta', '');
        }

        return '';
    }

    protected function sendRequest(Request $request): Response
    {
        return $this
            ->client
            ->withOptions(['stream' => true])
            ->post(
                'responses',
                array_merge([
                    'stream' => true,
                    'model' => $request->model(),
                    'input' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                    'max_output_tokens' => $request->maxTokens(),
                ], Arr::whereNotNull([
                    'temperature' => $request->temperature(),
                    'top_p' => $request->topP(),
                    'metadata' => $request->providerOptions('metadata'),
                    'tools' => $this->buildTools($request),
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                    'parallel_tool_calls' => $request->providerOptions('parallel_tool_calls'),
                    'previous_response_id' => $request->providerOptions('previous_response_id'),
                    'truncation' => $request->providerOptions('truncation'),
                    'reasoning' => $request->providerOptions('reasoning'),
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
    protected function handleErrors(array $data, Request $request): void
    {
        $code = data_get($data, 'error.code', 'unknown_error');

        if ($code === 'rate_limit_exceeded') {
            throw new PrismRateLimitedException([]);
        }

        throw new PrismException(sprintf(
            'Sending to model %s failed. Code: %s. Message: %s',
            $request->model(),
            $code,
            data_get($data, 'error.message', 'No error message provided')
        ));
    }
}
