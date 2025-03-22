<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\Anthropic\Concerns\ProcessesRateLimits;
use Prism\Prism\Providers\Anthropic\Maps\FinishReasonMap;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap;
use Prism\Prism\Providers\Anthropic\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Anthropic\Maps\ToolMap;
use Prism\Prism\Providers\Anthropic\ValueObjects\MessagePartWithCitations;
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

    protected string $text = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $toolCalls = [];

    protected ?string $contentBlockType = null;

    protected ?int $contentBlockIndex = null;

    protected ?string $thinking = null;

    protected ?string $thinkingSignature = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $citations = [];

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

            switch ($chunk['type']) {
                case 'ping':
                    break;

                case 'content_block_start':
                    $this->handleContentBlockStart($chunk);
                    break;

                case 'content_block_delta':
                    $chunkResult = $this->handleContentBlockDelta($chunk);
                    if ($chunkResult instanceof Chunk) {
                        yield $chunkResult;
                    }
                    break;

                case 'content_block_stop':
                    $this->contentBlockType = null;
                    $this->contentBlockIndex = null;
                    break;

                case 'citation_start':
                    $this->handleCitationStart($chunk);
                    break;

                case 'message_delta':
                    $stopReason = data_get($chunk, 'delta.stop_reason');
                    if ($stopReason === 'tool_use' && $this->toolCalls !== []) {
                        yield from $this->handleToolUseFinish($request, $depth);

                        return;
                    }
                    break;

                case 'message_stop':
                    $stopReason = data_get($chunk, 'stop_reason', '');
                    $finishReason = FinishReasonMap::map($stopReason);

                    if ($stopReason === 'tool_use' && $this->toolCalls !== []) {
                        yield from $this->handleToolUseFinish($request, $depth);

                        return;
                    }

                    $additionalContent = $this->buildAdditionalContent();

                    yield new Chunk(
                        text: '',
                        finishReason: $finishReason,
                        content: $additionalContent === [] ? null : (string) json_encode($additionalContent)
                    );

                    return;
            }
        }

        if ($this->toolCalls !== []) {
            yield from $this->handleToolUseFinish($request, $depth);
        }
    }

    protected function resetState(): void
    {
        $this->text = '';
        $this->toolCalls = [];
        $this->contentBlockType = null;
        $this->contentBlockIndex = null;
        $this->thinking = null;
        $this->thinkingSignature = null;
        $this->citations = [];
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function handleContentBlockStart(array $chunk): void
    {
        $this->contentBlockType = data_get($chunk, 'content_block.type');
        $this->contentBlockIndex = (int) data_get($chunk, 'index');

        if ($this->contentBlockType === 'thinking') {
            $this->thinking = '';
            $this->thinkingSignature = '';
        } elseif ($this->contentBlockType === 'tool_use') {
            $index = $this->contentBlockIndex;
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

        if ($this->contentBlockType === 'text') {
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

                    return new Chunk(
                        text: $textDelta,
                        finishReason: null
                    );
                }
            }
        } elseif ($this->contentBlockType === 'tool_use' && $deltaType === 'input_json_delta') {
            $jsonDelta = data_get($chunk, 'delta.partial_json', '');

            if (empty($jsonDelta)) {
                $jsonDelta = data_get($chunk, 'delta.input_json_delta.partial_json', '');
            }

            if ($this->contentBlockIndex !== null && isset($this->toolCalls[$this->contentBlockIndex])) {
                $this->toolCalls[$this->contentBlockIndex]['input'] .= $jsonDelta;
            }

            return null;
        } elseif ($this->contentBlockType === 'thinking') {
            if ($deltaType === 'thinking_delta') {
                $thinkingDelta = data_get($chunk, 'delta.thinking', '');

                if (empty($thinkingDelta)) {
                    $thinkingDelta = data_get($chunk, 'delta.thinking_delta.thinking', '');
                }

                $this->thinking .= $thinkingDelta;
            } elseif ($deltaType === 'signature_delta') {
                $signatureDelta = data_get($chunk, 'delta.signature', '');

                if (empty($signatureDelta)) {
                    $signatureDelta = data_get($chunk, 'delta.signature_delta.signature', '');
                }

                $this->thinkingSignature .= $signatureDelta;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function handleCitationStart(array $chunk): void
    {
        $citationId = data_get($chunk, 'citation.id');

        if (empty($citationId)) {
            return;
        }

        $this->citations[$citationId] = [
            'id' => $citationId,
            'start_index' => data_get($chunk, 'citation.start_index'),
            'end_index' => data_get($chunk, 'citation.end_index'),
            'text' => data_get($chunk, 'citation.text', ''),
            'urls' => data_get($chunk, 'citation.urls', []),
        ];
    }

    /**
     * @return array<int, MessagePartWithCitations>|null
     */
    protected function extractCitationsFromStream(): ?array
    {
        if ($this->citations === []) {
            return null;
        }

        $contentBlock = [
            'type' => 'text',
            'text' => $this->text,
            'citations' => $this->streamCitationsToAnthropicFormat(),
        ];

        return [MessagePartWithCitations::fromContentBlock($contentBlock)];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function streamCitationsToAnthropicFormat(): array
    {
        $result = [];

        foreach ($this->citations as $citation) {
            $result[] = [
                'type' => 'char_location',
                'cited_text' => $citation['text'],
                'start_char_index' => $citation['start_index'],
                'end_char_index' => $citation['end_index'],
                'document_index' => 0,
                'document_title' => null,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildAdditionalContent(): array
    {
        $additionalContent = [];

        if ($this->thinking !== null) {
            $additionalContent['thinking'] = $this->thinking;

            if ($this->thinkingSignature !== null) {
                $additionalContent['thinking_signature'] = $this->thinkingSignature;
            }
        }

        if ($this->citations !== []) {
            $messagePartsWithCitations = $this->extractCitationsFromStream();
            if ($messagePartsWithCitations !== null) {
                $additionalContent['messagePartsWithCitations'] = $messagePartsWithCitations;
            }
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
            content: $additionalContent === [] ? null : (string) json_encode($additionalContent)
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
    protected function handleToolCalls(
        Request $request,
        array $toolCalls,
        int $depth,
        ?array $additionalContent = null
    ): Generator {
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
            $payload = array_filter([
                'stream' => true,
                'model' => $request->model(),
                'system' => MessageMap::mapSystemMessages($request->systemPrompts()),
                'messages' => MessageMap::map($request->messages(), $request->providerMeta(Provider::Anthropic)),
                'thinking' => $request->providerMeta(Provider::Anthropic, 'thinking.enabled') === true
                    ? [
                        'type' => 'enabled',
                        'budget_tokens' => is_int($request->providerMeta(Provider::Anthropic, 'thinking.budgetTokens'))
                            ? $request->providerMeta(Provider::Anthropic, 'thinking.budgetTokens')
                            : config('prism.anthropic.default_thinking_budget', 1024),
                    ]
                    : null,
                'max_tokens' => $request->maxTokens(),
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'tools' => ToolMap::map($request->tools()),
                'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            ]);

            return $this->client
                ->withOptions(['stream' => true])
                ->throw()
                ->post('messages', $payload);
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
