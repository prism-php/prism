<?php

namespace Prism\src\Providers\DeepSeek\Handlers;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Providers\DeepSeek\Concerns\MapsFinishReason;
use Prism\Prism\Providers\DeepSeek\Maps\MessageMap;
use Prism\Prism\Providers\DeepSeek\Maps\ToolChoiceMap;
use Prism\Prism\Providers\DeepSeek\Maps\ToolMap;
use Prism\Prism\Providers\DeepSeek\Concerns\ValidatesResponses;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream
{
    use CallsTools;
    use MapsFinishReason;
    use ValidatesResponses;

    /**
     * @param PendingRequest $client
     */
    public function __construct(protected PendingRequest $client) {}

    /**
     * @param Request $request
     * @return Generator
     * @throws ConnectionException
     */
    public function handle(Request $request): Generator
    {
        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    protected function processStream(Response $response, Request $request): Generator
    {
        $meta = null;

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            if (isset($data['id']) && ! $meta instanceof \Prism\Prism\ValueObjects\Meta) {
                $meta = new Meta(
                    id: $data['id'],
                    model: $data['model'] ?? null,
                );

                yield new Chunk(
                    text: '',
                    finishReason: null,
                    meta: $meta,
                    chunkType: ChunkType::Meta,
                );
            }

            $reasoningDelta = $this->extractReasoningDelta($data);
            if (!empty($reasoningDelta)) {
                yield new Chunk(
                    text: $reasoningDelta,
                    finishReason: null,
                    chunkType: ChunkType::Thinking
                );
                continue;
            }

            $content = $this->extractContentDelta($data);
            if (!empty($content)) {
                yield new Chunk(
                    text: $content,
                    finishReason: null
                );
                continue;
            }

            $usage = $this->extractUsage($data);
            if ($usage !== null) {
                $usageData = new Usage(
                    promptTokens: data_get($data, 'usage.prompt_tokens'),
                    completionTokens: data_get($data, 'usage.completion_tokens')
                );

                yield new Chunk(
                    text: '',
                    usage: $usageData,
                    chunkType: ChunkType::Meta
                );
            }

            $finishReason = $this->extractFinishReason($data);
            if ($finishReason !== FinishReason::Unknown) {

                yield new Chunk(
                    text: '',
                    finishReason: $finishReason,
                    chunkType: ChunkType::Meta,
                );

                return;
            }
        }
    }

    /**
     * @param StreamInterface $stream
     * @return array|null
     * @throws PrismChunkDecodeException
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $line = trim(substr($line, strlen('data: ')));

        if (Str::contains($line, '[DONE]')) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismChunkDecodeException('DeepSeek', $e);
        }
    }

    /**
     * @param array $data
     * @return string
     */
    protected function extractReasoningDelta(array $data): ?string
    {
        return data_get($data, 'choices.0.delta.reasoning_content') ?? '';
    }

    /**
     * @param array $data
     * @return string
     */
    protected function extractContentDelta(array $data): string
    {
        return data_get($data, 'choices.0.delta.content') ?? '';
    }

    /**
     * @param array $data
     * @return FinishReason
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        $finishReason = data_get($data, 'choices.0.finish_reason');

        if ($finishReason === null) {
            return FinishReason::Unknown;
        }

        return $this->mapFinishReason($data);
    }

    protected function extractUsage(array $data): ?array
    {
        return data_get($data, 'usage');
    }

    /**
     * @param Request $request
     * @return Response
     * @throws ConnectionException
     */
    protected function sendRequest(Request $request): Response
    {
        return $this->client->post(
            'chat/completions',
            array_merge([
                'stream' => true,
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_tokens' => $request->maxTokens(),
            ], Arr::whereNotNull([
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'tools' => ToolMap::map($request->tools()) ?: null,
                'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            ]))
        );
    }

    /**
     * @param StreamInterface $stream
     * @return string
     */
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
