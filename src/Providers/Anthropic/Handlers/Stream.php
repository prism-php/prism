<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Pest\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\Anthropic\Concerns\HandlesResponse;
use Prism\Prism\Providers\Anthropic\Maps\FinishReasonMap;
use Prism\Prism\Providers\Anthropic\ValueObjects\MessagePartWithCitations;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream
{
    use CallsTools, HandlesResponse;

    protected string $model = '';

    protected string $requestId = '';

    protected string $text = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $toolCalls = [];

    protected string $thinking = '';

    protected string $thinkingSignature = '';

    /**
     * @var MessagePartWithCitations[]
     */
    protected array $citations = [];

    protected ?string $tempContentBlockType = null;

    protected ?int $tempContentBlockIndex = null;

    /**
     * @var array<string,mixed>|null
     */
    protected ?array $tempCitation = null;

    protected string $stopReason = '';

    public function __construct(protected PendingRequest $client) {}

    /**
     * @return Generator<Chunk>
     *
     * @throws PrismChunkDecodeException
     * @throws PrismException
     * @throws PrismRateLimitedException
     */
    public function handle(Request $request): Generator
    {
        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * @return Generator<Chunk>
     *
     * @throws PrismChunkDecodeException
     * @throws PrismException
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        $this->resetState();

        if ($depth >= $request->maxSteps()) {
            throw new PrismException('Maximum tool call chain depth exceeded');
        }

        while (! $response->getBody()->eof()) {
            $chunk = $this->parseNextChunk($response->getBody());

            if ($chunk === null) {
                continue;
            }

            $outcome = match ($chunk['type']) {
                // Returns a meta chunk with the request ID and model to yield
                'message_start' => $this->handleMessageStart(response: $response, chunk: $chunk),

                // States temporary content block state and adds any tool use to tool calls
                'content_block_start' => $this->handleContentBlockStart($chunk),

                // Adds text, thinking and tool inputs to state. For text and thinking, returns a chunk to yield.
                'content_block_delta' => $this->handleContentBlockDelta($chunk),

                // Resets temporary content block state
                'content_block_stop' => $this->handleContentBlockStop(),

                // Handles a tool use if finish is tool use
                'message_delta' => $this->handleMessageDelta($chunk, $request, $depth),

                // Sends a final meta chunk with the final text, finish reason, meta and additionalContent
                'message_stop' => $this->handleMessageStop($response, $request, $depth),

                'error' => $this->handleError($chunk),

                // E.g. ping
                default => null
            };

            if ($outcome instanceof Generator) {
                yield from $outcome;
            }

            if ($outcome instanceof Chunk) {
                yield $outcome;
            }
        }

        if ($this->toolCalls !== []) {
            yield from $this->handleToolUseFinish($request, $depth);
        }
    }

    /**
     * @param  array<string,mixed>  $chunk
     */
    protected function handleMessageStart(Response $response, array $chunk): Chunk
    {
        $this->model = data_get($chunk, 'message.model', '');
        $this->requestId = data_get($chunk, 'message.id', '');

        return new Chunk(
            text: '',
            finishReason: null,
            meta: new Meta(
                id: $this->requestId,
                model: $this->model,
                rateLimits: $this->processRateLimits($response)
            ),
            chunkType: ChunkType::Meta
        );
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function handleContentBlockStart(array $chunk): void
    {
        $this->tempContentBlockType = data_get($chunk, 'content_block.type');
        $this->tempContentBlockIndex = (int) data_get($chunk, 'index');

        if ($this->tempContentBlockType === 'tool_use') {
            $index = $this->tempContentBlockIndex;

            $this->toolCalls[$index] = [
                'id' => data_get($chunk, 'content_block.id'),
                'name' => data_get($chunk, 'content_block.name'),
                'input' => '',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function handleContentBlockDelta(array $chunk): ?Chunk
    {
        $deltaType = data_get($chunk, 'delta.type');

        if ($this->tempContentBlockType === 'text') {
            if ($deltaType === 'text_delta') {
                $textDelta = data_get($chunk, 'delta.text', '');

                if (empty($textDelta)) {
                    $textDelta = data_get($chunk, 'delta.text_delta.text', '');
                }

                if (empty($textDelta)) {
                    $textDelta = data_get($chunk, 'text', '');
                }

                if (! empty($textDelta)) {
                    $this->text .= $textDelta;

                    $additionalContent = [];

                    if ($this->tempCitation !== null) {
                        $this->citations[] = MessagePartWithCitations::fromContentBlock([
                            'text' => $textDelta,
                            'citations' => [$this->tempCitation],
                        ]);

                        $additionalContent['citationIndex'] = array_key_last($this->citations);
                    }

                    return new Chunk(
                        text: $textDelta,
                        finishReason: null,
                        chunkType: ChunkType::Message,
                        additionalContent: $additionalContent
                    );
                }
            }

            if ($deltaType === 'citations_delta') {
                $this->tempCitation = $this->extractCitationsFromChunk($chunk);
            }
        }

        if ($this->tempContentBlockType === 'tool_use' && $deltaType === 'input_json_delta') {
            $jsonDelta = data_get($chunk, 'delta.partial_json', '');

            if (empty($jsonDelta)) {
                $jsonDelta = data_get($chunk, 'delta.input_json_delta.partial_json', '');
            }

            if ($this->tempContentBlockIndex !== null && isset($this->toolCalls[$this->tempContentBlockIndex])) {
                $this->toolCalls[$this->tempContentBlockIndex]['input'] .= $jsonDelta;
            }

            return null;
        }

        if ($this->tempContentBlockType === 'thinking') {
            if ($deltaType === 'thinking_delta') {
                $thinkingDelta = data_get($chunk, 'delta.thinking', '');

                if (empty($thinkingDelta)) {
                    $thinkingDelta = data_get($chunk, 'delta.thinking_delta.thinking', '');
                }

                $this->thinking .= $thinkingDelta;

                return new Chunk(
                    text: $thinkingDelta,
                    finishReason: null,
                    chunkType: ChunkType::Thinking
                );
            }
            if ($deltaType === 'signature_delta') {

                $signatureDelta = data_get($chunk, 'delta.signature', '');

                if (empty($signatureDelta)) {
                    $signatureDelta = data_get($chunk, 'delta.signature_delta.signature', '');
                }

                $this->thinkingSignature .= $signatureDelta;
            }
        }

        return null;
    }

    protected function handleContentBlockStop(): void
    {
        $this->tempContentBlockType = null;
        $this->tempContentBlockIndex = null;
        $this->tempCitation = null;
    }

    /**
     * @param  array<string,mixed>  $chunk
     */
    protected function handleMessageDelta(array $chunk, Request $request, int $depth): ?Generator
    {
        $this->stopReason = data_get($chunk, 'delta.stop_reason', $this->stopReason);

        if ($this->stopReason === 'tool_use' && $this->toolCalls !== []) {
            return $this->handleToolUseFinish($request, $depth);
        }

        return null;
    }

    /**
     * @throws PrismChunkDecodeException
     * @throws PrismException
     * @throws PrismRateLimitedException
     */
    protected function handleMessageStop(Response $response, Request $request, int $depth): Generator|Chunk
    {
        if ($this->stopReason === 'tool_use' && $this->toolCalls !== []) {
            return $this->handleToolUseFinish($request, $depth);
        }

        return new Chunk(
            text: $this->text,
            finishReason: FinishReasonMap::map($this->stopReason),
            meta: new Meta(
                id: $this->requestId,
                model: $this->model,
                rateLimits: $this->processRateLimits($response)
            ),
            additionalContent: $this->buildAdditionalContent(),
            chunkType: ChunkType::Meta
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildAdditionalContent(): array
    {
        $additionalContent = [];

        if ($this->thinking !== '') {
            $additionalContent['thinking'] = $this->thinking;

            if ($this->thinkingSignature !== null) {
                $additionalContent['thinking_signature'] = $this->thinkingSignature;
            }
        }

        if ($this->citations !== []) {
            $additionalContent['messagePartsWithCitations'] = $this->citations;
        }

        return $additionalContent;
    }

    /**
     * @return Generator<Chunk>
     *
     * @throws PrismChunkDecodeException
     * @throws PrismException
     * @throws PrismRateLimitedException
     */
    protected function handleToolUseFinish(Request $request, int $depth): Generator
    {
        $mappedToolCalls = $this->mapToolCalls();
        $additionalContent = $this->buildAdditionalContent();

        yield new Chunk(
            text: '',
            toolCalls: $mappedToolCalls,
            finishReason: null,
            additionalContent: $additionalContent
        );

        yield from $this->handleToolCalls($request, $mappedToolCalls, $depth, $additionalContent);
    }

    /**
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(): array
    {
        return array_values(array_map(function (array $toolCall): ToolCall {
            $input = data_get($toolCall, 'input');
            if (is_string($input) && $this->isValidJson($input)) {
                $input = json_decode($input, true);
            }

            return new ToolCall(
                id: data_get($toolCall, 'id'),
                name: data_get($toolCall, 'name'),
                arguments: $input
            );
        }, $this->toolCalls));
    }

    protected function isValidJson(string $string): bool
    {
        if ($string === '' || $string === '0') {
            return false;
        }

        try {
            json_decode($string, true, 512, JSON_THROW_ON_ERROR);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws PrismChunkDecodeException
     */
    protected function parseNextChunk(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);
        $line = trim($line);

        if ($line === '' || $line === '0') {
            return null;
        }

        if (str_starts_with($line, 'event:')) {
            $eventType = trim(substr($line, strlen('event:')));

            if ($eventType === 'ping') {
                return ['type' => 'ping'];
            }

            $dataLine = $this->readLine($stream);

            if (in_array(trim($dataLine), ['', '0'], true)) {
                return ['type' => $eventType];
            }

            if (! str_starts_with(trim($dataLine), 'data:')) {
                return ['type' => $eventType];
            }

            $jsonData = trim(substr(trim($dataLine), strlen('data:')));

            if ($jsonData === '' || $jsonData === '0') {
                return ['type' => $eventType];
            }

            try {
                $data = json_decode($jsonData, true, flags: JSON_THROW_ON_ERROR);
                $data['type'] = $eventType;

                return $data;
            } catch (Throwable $e) {
                throw new PrismChunkDecodeException('Anthropic', $e);
            }
        }

        if (str_starts_with($line, 'data:')) {
            $jsonData = trim(substr($line, strlen('data:')));

            if ($jsonData === '' || $jsonData === '0' || str_contains($jsonData, 'DONE')) {
                return null;
            }

            try {
                return json_decode($jsonData, true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                throw new PrismChunkDecodeException('Anthropic', $e);
            }
        }

        return null;
    }

    /**
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<string, mixed>|null  $additionalContent
     * @return Generator<Chunk>
     *
     * @throws PrismChunkDecodeException
     * @throws PrismException
     * @throws PrismRateLimitedException
     */
    protected function handleToolCalls(Request $request, array $toolCalls, int $depth, ?array $additionalContent = null): Generator
    {
        $toolResults = $this->callTools($request->tools(), $toolCalls);

        $request->addMessage(new AssistantMessage($this->text, $toolCalls, $additionalContent ?? []));
        $request->addMessage(new ToolResultMessage($toolResults));

        yield new Chunk(
            text: '',
            toolResults: $toolResults,
        );

        $nextResponse = $this->sendRequest($request);
        yield from $this->processStream($nextResponse, $request, $depth + 1);
    }

    /**
     * @throws PrismRateLimitedException
     * @throws PrismException
     */
    protected function sendRequest(Request $request): Response
    {
        try {
            return $this->client
                ->withOptions(['stream' => true])
                ->throw()
                ->post('messages', array_filter([
                    'stream' => true,
                    ...Text::buildHttpRequestPayload($request),
                ]));
        } catch (Throwable $e) {
            if ($e instanceof RequestException && in_array($e->response->getStatusCode(), [413, 429, 529])) {
                $this->handleResponseExceptions($e->response);
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

    /**
     * @param  array<string,mixed>  $chunk
     * @return array<string,mixed>
     */
    protected function extractCitationsFromChunk(array $chunk): array
    {
        $citation = data_get($chunk, 'delta.citation', null);

        if (Arr::has($citation, 'start_page_number')) {
            $type = 'page_location';
        } elseif (Arr::has($citation, 'start_char_index')) {
            $type = 'char_location';
        } elseif (Arr::has($citation, 'start_block_index')) {
            $type = 'content_block_location';
        } else {
            throw new InvalidArgumentException('Citation type could not be detected from signature.');
        }

        return [
            'type' => $type,
            ...$citation,
        ];
    }

    protected function resetState(): void
    {
        $this->model = '';
        $this->requestId = '';
        $this->text = '';
        $this->toolCalls = [];
        $this->thinking = '';
        $this->thinkingSignature = '';
        $this->citations = [];
        $this->stopReason = '';

        $this->tempContentBlockType = null;
        $this->tempContentBlockIndex = null;
    }

    /**
     * @param  array<string, mixed>  $chunk
     *
     * @throws PrismProviderOverloadedException
     * @throws PrismException
     */
    protected function handleError(array $chunk): void
    {
        if (data_get($chunk, 'error.type') === 'overloaded_error') {
            throw new PrismProviderOverloadedException('Anthropic');
        }

        throw PrismException::providerResponseError(vsprintf(
            'Anthropic Error: [%s] %s',
            [
                data_get($chunk, 'error.type', 'unknown'),
                data_get($chunk, 'error.message'),
            ]
        ));
    }
}
