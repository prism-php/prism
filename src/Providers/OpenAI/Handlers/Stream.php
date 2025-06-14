<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessesRateLimits;
use Prism\Prism\Providers\OpenAI\Maps\FinishReasonMap;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolMap;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream
{
    use CallsTools, ProcessesRateLimits;

    /**
     * Temporarily stores the ID of the last reasoning item streamed. The ID
     * must be attached to the next function_call item so we can include the
     * required reasoning object when we send the follow-up request with tool
     * results.
     */
    protected ?string $pendingReasoningId = null;

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

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            if (Str::startsWith(data_get($data, 'type', ''), 'response.reasoning')) {
                $this->pendingReasoningId = data_get($data, 'id')
                    ?? data_get($data, 'reasoning.id');

                continue;
            }

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                continue;
            }

            if ($this->isFinalEvent($data)) {
                if ($toolCalls !== []) {
                    yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);

                    return;
                }

                yield new Chunk(
                    text: '',
                    finishReason: FinishReason::Stop,
                );

                return;
            }

            $content = $this->extractContent($data);

            if ($content === '') {
                continue;
            }

            $text .= $content;

            $finishReason = $this->mapFinishReason($data);

            yield new Chunk(
                text: $content,
                finishReason: $finishReason !== FinishReason::Unknown ? $finishReason : null
            );
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
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        $type = data_get($data, 'type', '');

        if ($type === 'response.output_item.added' && data_get($data, 'item.type') === 'function_call') {
            $index = (int) data_get($data, 'output_index', count($toolCalls));

            $toolCalls[$index]['id'] = data_get($data, 'item.id');
            $toolCalls[$index]['call_id'] = data_get($data, 'item.call_id');
            $toolCalls[$index]['name'] = data_get($data, 'item.name');
            $toolCalls[$index]['arguments'] = '';

            if ($this->pendingReasoningId !== null) {
                $toolCalls[$index]['reasoning_id'] = $this->pendingReasoningId;
                $this->pendingReasoningId = null;
            }

            return $toolCalls;
        }

        if ($type === 'response.function_call_arguments.delta') {
            $callId = data_get($data, 'item_id');
            $delta = data_get($data, 'delta', '');

            foreach ($toolCalls as &$call) {
                if (($call['id'] ?? null) === $callId) {
                    $call['arguments'] .= $delta;
                    break;
                }
            }

            return $toolCalls;
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
                resultId: data_get($toolCall, 'call_id') ?? data_get($toolCall, 'id'),
                reasoningId: data_get($toolCall, 'reasoning_id'),
                reasoningSummary: data_get($toolCall, 'reasoning_summary'),
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
    protected function mapFinishReason(array $data): FinishReason
    {
        $eventType = Str::after(data_get($data, 'type'), 'response.');
        $lastOutputType = data_get($data, 'response.output.{last}.type');

        return FinishReasonMap::map($eventType, $lastOutputType);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    protected function isFinalEvent(array $data): bool
    {
        return data_get($data, 'type') === 'response.completed';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractContent(array $data): string
    {
        if (Str::contains(data_get($data, 'type', ''), 'text.delta')) {
            return (string) data_get($data, 'delta', '');
        }

        return '';
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
                    ], Arr::whereNotNull([
                        'temperature' => $request->temperature(),
                        'top_p' => $request->topP(),
                        'metadata' => $request->providerOptions('metadata'),
                        'tools' => ToolMap::map($request->tools()),
                        'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                        'previous_response_id' => $request->providerOptions('previous_response_id'),
                        'truncation' => $request->providerOptions('truncation'),
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
