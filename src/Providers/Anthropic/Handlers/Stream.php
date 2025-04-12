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
use Prism\Prism\Providers\Anthropic\ValueObjects\StreamState;
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

    protected StreamState $state;

    public function __construct(protected PendingRequest $client)
    {
        $this->state = new StreamState;
    }

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
        $this->state->reset();

        $this->validateToolCallDepth($request, $depth);

        yield from $this->processStreamChunks($response, $request, $depth);

        if ($this->state->hasToolCalls()) {
            yield from $this->handleToolUseFinish($request, $depth);
        }
    }

    /**
     * @throws PrismException
     */
    protected function validateToolCallDepth(Request $request, int $depth): void
    {
        if ($depth >= $request->maxSteps()) {
            throw new PrismException('Maximum tool call chain depth exceeded');
        }
    }

    /**
     * @return Generator<Chunk>
     *
     * @throws PrismChunkDecodeException
     * @throws PrismException
     */
    protected function processStreamChunks(Response $response, Request $request, int $depth): Generator
    {
        while (! $response->getBody()->eof()) {
            $chunk = $this->parseNextChunk($response->getBody());

            if ($chunk === null) {
                continue;
            }

            $outcome = $this->processChunk($chunk, $response, $request, $depth);

            if ($outcome instanceof Generator) {
                yield from $outcome;
            }

            if ($outcome instanceof Chunk) {
                yield $outcome;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $chunk
     *
     * @throws PrismChunkDecodeException
     * @throws PrismException
     * @throws PrismRateLimitedException
     */
    protected function processChunk(array $chunk, Response $response, Request $request, int $depth): Generator|Chunk|null
    {
        return match ($chunk['type'] ?? null) {
            'message_start' => $this->handleMessageStart($response, $chunk),
            'content_block_start' => $this->handleContentBlockStart($chunk),
            'content_block_delta' => $this->handleContentBlockDelta($chunk),
            'content_block_stop' => $this->handleContentBlockStop(),
            'message_delta' => $this->handleMessageDelta($chunk, $request, $depth),
            'message_stop' => $this->handleMessageStop($response, $request, $depth),
            'error' => $this->handleError($chunk),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function handleMessageStart(Response $response, array $chunk): Chunk
    {
        $this->state
            ->setModel(data_get($chunk, 'message.model', ''))
            ->setRequestId(data_get($chunk, 'message.id', ''));

        return new Chunk(
            text: '',
            finishReason: null,
            meta: new Meta(
                id: $this->state->requestId(),
                model: $this->state->model(),
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
        $blockType = data_get($chunk, 'content_block.type');
        $blockIndex = (int) data_get($chunk, 'index');

        $this->state
            ->setTempContentBlockType($blockType)
            ->setTempContentBlockIndex($blockIndex);

        if ($blockType === 'tool_use') {
            $this->state->addToolCall($blockIndex, [
                'id' => data_get($chunk, 'content_block.id'),
                'name' => data_get($chunk, 'content_block.name'),
                'input' => '',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function handleContentBlockDelta(array $chunk): ?Chunk
    {
        $deltaType = data_get($chunk, 'delta.type');
        $blockType = $this->state->tempContentBlockType();

        if ($blockType === 'text') {
            return $this->handleTextBlockDelta($chunk, $deltaType);
        }

        if ($blockType === 'tool_use' && $deltaType === 'input_json_delta') {
            return $this->handleToolInputDelta($chunk);
        }

        if ($blockType === 'thinking') {
            return $this->handleThinkingBlockDelta($chunk, $deltaType);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function handleTextBlockDelta(array $chunk, ?string $deltaType): ?Chunk
    {
        if ($deltaType === 'text_delta') {
            $textDelta = $this->extractTextDelta($chunk);

            if ($textDelta !== '' && $textDelta !== '0') {
                $this->state->appendText($textDelta);
                $additionalContent = $this->buildCitationContent();

                return new Chunk(
                    text: $textDelta,
                    finishReason: null,
                    chunkType: ChunkType::Text,
                    additionalContent: $additionalContent
                );
            }
        }

        if ($deltaType === 'citations_delta') {
            $this->state->setTempCitation($this->extractCitationsFromChunk($chunk));
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildCitationContent(): array
    {
        $additionalContent = [];

        if ($this->state->tempCitation() !== null) {
            $this->state->addCitation(MessagePartWithCitations::fromContentBlock([
                'text' => $this->state->text(),
                'citations' => [$this->state->tempCitation()],
            ]));

            $additionalContent['citationIndex'] = count($this->state->citations()) - 1;
        }

        return $additionalContent;
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function extractTextDelta(array $chunk): string
    {
        $textDelta = data_get($chunk, 'delta.text', '');

        if (empty($textDelta)) {
            $textDelta = data_get($chunk, 'delta.text_delta.text', '');
        }

        if (empty($textDelta)) {
            return data_get($chunk, 'text', '');
        }

        return $textDelta;
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function handleToolInputDelta(array $chunk): ?Chunk
    {
        $jsonDelta = data_get($chunk, 'delta.partial_json', '');

        if (empty($jsonDelta)) {
            $jsonDelta = data_get($chunk, 'delta.input_json_delta.partial_json', '');
        }

        $blockIndex = $this->state->tempContentBlockIndex();

        if ($blockIndex !== null) {
            $this->state->appendToolCallInput($blockIndex, $jsonDelta);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function handleThinkingBlockDelta(array $chunk, ?string $deltaType): ?Chunk
    {
        if ($deltaType === 'thinking_delta') {
            $thinkingDelta = data_get($chunk, 'delta.thinking', '');

            if (empty($thinkingDelta)) {
                $thinkingDelta = data_get($chunk, 'delta.thinking_delta.thinking', '');
            }

            $this->state->appendThinking($thinkingDelta);

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

            $this->state->appendThinkingSignature($signatureDelta);
        }

        return null;
    }

    protected function handleContentBlockStop(): void
    {
        $this->state->resetContentBlock();
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function handleMessageDelta(array $chunk, Request $request, int $depth): ?Generator
    {
        $stopReason = data_get($chunk, 'delta.stop_reason', '');

        if (! empty($stopReason)) {
            $this->state->setStopReason($stopReason);
        }

        if ($this->state->isToolUseFinish()) {
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
        if ($this->state->isToolUseFinish()) {
            return $this->handleToolUseFinish($request, $depth);
        }

        return new Chunk(
            text: $this->state->text(),
            finishReason: FinishReasonMap::map($this->state->stopReason()),
            meta: new Meta(
                id: $this->state->requestId(),
                model: $this->state->model(),
                rateLimits: $this->processRateLimits($response)
            ),
            additionalContent: $this->state->buildAdditionalContent(),
            chunkType: ChunkType::Meta
        );
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
        $additionalContent = $this->state->buildAdditionalContent();

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
        }, $this->state->toolCalls()));
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
            return $this->parseEventChunk($line, $stream);
        }

        if (str_starts_with($line, 'data:')) {
            return $this->parseDataChunk($line);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws PrismChunkDecodeException
     */
    protected function parseEventChunk(string $line, StreamInterface $stream): ?array
    {
        $eventType = trim(substr($line, strlen('event:')));

        if ($eventType === 'ping') {
            return ['type' => 'ping'];
        }

        $dataLine = $this->readLine($stream);
        $dataLine = trim($dataLine);

        if ($dataLine === '' || $dataLine === '0') {
            return ['type' => $eventType];
        }

        if (! str_starts_with($dataLine, 'data:')) {
            return ['type' => $eventType];
        }

        return $this->parseJsonData($dataLine, $eventType);
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws PrismChunkDecodeException
     */
    protected function parseDataChunk(string $line): ?array
    {
        $jsonData = trim(substr($line, strlen('data:')));

        if ($jsonData === '' || $jsonData === '0' || str_contains($jsonData, 'DONE')) {
            return null;
        }

        return $this->parseJsonData($jsonData);
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws PrismChunkDecodeException
     */
    protected function parseJsonData(string $jsonDataLine, ?string $eventType = null): ?array
    {
        $jsonData = trim(str_starts_with($jsonDataLine, 'data:')
            ? substr($jsonDataLine, strlen('data:'))
            : $jsonDataLine);

        if ($jsonData === '' || $jsonData === '0') {
            return $eventType ? ['type' => $eventType] : null;
        }

        try {
            $data = json_decode($jsonData, true, flags: JSON_THROW_ON_ERROR);

            if ($eventType) {
                $data['type'] = $eventType;
            }

            return $data;
        } catch (Throwable $e) {
            throw new PrismChunkDecodeException('Anthropic', $e);
        }
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

        $this->addMessagesToRequest($request, $toolResults, $additionalContent);

        yield new Chunk(
            text: '',
            toolResults: $toolResults,
        );

        $nextResponse = $this->sendRequest($request);
        yield from $this->processStream($nextResponse, $request, $depth + 1);
    }

    /**
     * @param  array<int|string, mixed>  $toolResults
     * @param  array<string, mixed>|null  $additionalContent
     */
    protected function addMessagesToRequest(Request $request, array $toolResults, ?array $additionalContent): void
    {
        $request->addMessage(new AssistantMessage(
            $this->state->text(),
            $this->mapToolCalls(),
            $additionalContent ?? []
        ));

        $request->addMessage(new ToolResultMessage($toolResults));
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
     * @param  array<string, mixed>  $chunk
     * @return array<string, mixed>
     */
    protected function extractCitationsFromChunk(array $chunk): array
    {
        $citation = data_get($chunk, 'delta.citation', null);

        $type = $this->determineCitationType($citation);

        return [
            'type' => $type,
            ...$citation,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $citation
     */
    protected function determineCitationType(?array $citation): string
    {
        if ($citation === null) {
            throw new InvalidArgumentException('Citation cannot be null.');
        }

        if (Arr::has($citation, 'start_page_number')) {
            return 'page_location';
        }

        if (Arr::has($citation, 'start_char_index')) {
            return 'char_location';
        }

        if (Arr::has($citation, 'start_block_index')) {
            return 'content_block_location';
        }

        throw new InvalidArgumentException('Citation type could not be detected from signature.');
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
