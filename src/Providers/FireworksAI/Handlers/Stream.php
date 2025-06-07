<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\FireworksAI\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\FireworksAI\Concerns\ValidateResponse;
use Prism\Prism\Providers\FireworksAI\Maps\MessageMap;
use Prism\Prism\Providers\FireworksAI\Maps\ToolChoiceMap;
use Prism\Prism\Providers\OpenAI\Maps\FinishReasonMap;
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
    use CallsTools, ValidateResponse;

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

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                continue;
            }

            if ($this->mapFinishReason($data) === FinishReason::ToolCalls) {
                yield from $this->handleToolCalls($request, $text, $toolCalls, $depth);

                return;
            }

            $content = data_get($data, 'choices.0.delta.content', '') ?? '';
            $text .= $content;

            $finishReason = $this->mapFinishReason($data);

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
            throw new PrismChunkDecodeException('FireworksAI', $e);
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
    protected function handleToolCalls(
        Request $request,
        string $text,
        array $toolCalls,
        int $depth
    ): Generator {
        $toolCalls = $this->mapToolCalls($toolCalls);

        $toolResults = $this->callTools($request->tools(), $toolCalls);

        $request->addMessage(new AssistantMessage($text, $toolCalls));
        $request->addMessage(new ToolResultMessage($toolResults));

        yield new Chunk(
            text: '',
            toolCalls: $toolCalls,
            toolResults: $toolResults,
        );

        $nextResponse = $this->sendRequest($request);
        yield from $this->processStream($nextResponse, $request, $depth + 1);
    }

    /**
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
    protected function mapFinishReason(array $data): FinishReason
    {
        return FinishReasonMap::map(data_get($data, 'choices.0.finish_reason') ?? '');
    }

    protected function sendRequest(Request $request): Response
    {
        try {
            $body = array_merge([
                'stream' => true,
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_tokens' => $request->maxTokens(),
            ], Arr::whereNotNull([
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'metadata' => $request->providerOptions('metadata'),
                'tools' => ToolMap::map($request->tools()),
                'tool_choice' => $this->mapToolChoice($request),
                // FireworksAI-specific parameters
                'context_length_exceeded_behavior' => $request->providerOptions('context_length_exceeded_behavior'),
                'repetition_penalty' => $request->providerOptions('repetition_penalty'),
                'mirostat_lr' => $request->providerOptions('mirostat_lr'),
                'mirostat_target' => $request->providerOptions('mirostat_target'),
                'raw_output' => $request->providerOptions('raw_output'),
                'echo' => $request->providerOptions('echo'),
            ]));

            if ($grammar = $request->providerOptions('grammar')) {
                $body['response_format'] = [
                    'type' => 'grammar',
                    'grammar' => $grammar,
                ];
            }

            return $this
                ->client
                ->withOptions(['stream' => true])
                ->throw()
                ->post('chat/completions', $body);
        } catch (Throwable $e) {
            if ($e instanceof RequestException && $e->response->getStatusCode() === 429) {
                throw new PrismRateLimitedException($this->processRateLimits($e->response));
            }

            throw PrismException::providerRequestError($request->model(), $e);
        }
    }

    /**
     * Map tool choice with support for FireworksAI's "any" option
     *
     * @return array<string, mixed>|string|null
     */
    protected function mapToolChoice(Request $request): array|string|null
    {
        $toolChoice = $request->toolChoice();

        if ($toolChoice === null) {
            return null;
        }

        if ($request->providerOptions('tool_choice') === 'any') {
            return 'any';
        }

        return ToolChoiceMap::map($toolChoice);
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
