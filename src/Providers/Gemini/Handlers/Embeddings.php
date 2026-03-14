<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Prism\Prism\Embeddings\Content;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Gemini\Concerns\ValidatesResponse;
use Prism\Prism\Providers\Gemini\Maps\AudioVideoMapper;
use Prism\Prism\Providers\Gemini\Maps\DocumentMapper;
use Prism\Prism\Providers\Gemini\Maps\ImageMapper;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Media\Video;
use Prism\Prism\ValueObjects\Meta;

class Embeddings
{
    use ValidatesResponse;

    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): EmbeddingsResponse
    {
        if (count($request->contents()) === 1) {
            return $this->handleSingleRequest($request);
        }

        return $this->handleBatchRequest($request);
    }

    protected function handleSingleRequest(Request $request): EmbeddingsResponse
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        if (! isset($data['embedding'])) {
            throw PrismException::providerResponseError(
                'Gemini Error: Invalid response format or missing embedding data'
            );
        }

        return new EmbeddingsResponse(
            embeddings: [Embedding::fromArray(data_get($data, 'embedding.values', []))],
            usage: new EmbeddingsUsage(0), // Gemini doesn't provide token usage info,
            meta: new Meta(
                id: '',
                model: '',
            ),
            raw: $data,
        );
    }

    protected function handleBatchRequest(Request $request): EmbeddingsResponse
    {
        $response = $this->sendBatchRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        if (! isset($data['embeddings'])) {
            throw PrismException::providerResponseError(
                'Gemini Error: Invalid batch response format or missing embeddings data'
            );
        }

        $embeddings = array_map(
            fn (array $embedding): Embedding => Embedding::fromArray($embedding['values'] ?? []),
            $data['embeddings'],
        );

        return new EmbeddingsResponse(
            embeddings: $embeddings,
            usage: new EmbeddingsUsage(0),
            meta: new Meta(
                id: '',
                model: '',
            ),
            raw: $data,
        );
    }

    protected function sendRequest(Request $request): Response
    {
        $providerOptions = $request->providerOptions();

        /** @var Response $response */
        $response = $this->client->post(
            "{$request->model()}:embedContent",
            Arr::whereNotNull([
                'model' => $this->formatModel($request->model()),
                'content' => $this->mapContent($request->contents()[0]),
                'title' => $providerOptions['title'] ?? null,
                'taskType' => $providerOptions['taskType'] ?? null,
                'outputDimensionality' => $providerOptions['outputDimensionality'] ?? null,
            ])
        );

        return $response;
    }

    protected function sendBatchRequest(Request $request): Response
    {
        $providerOptions = $request->providerOptions();
        $model = $request->model();

        $requests = array_map(
            fn (Content $content): array => Arr::whereNotNull([
                'model' => $this->formatModel($model),
                'content' => $this->mapContent($content),
                'title' => $providerOptions['title'] ?? null,
                'taskType' => $providerOptions['taskType'] ?? null,
                'outputDimensionality' => $providerOptions['outputDimensionality'] ?? null,
            ]),
            $request->contents(),
        );

        /** @var Response $response */
        $response = $this->client->post(
            "{$model}:batchEmbedContents",
            ['requests' => $requests],
        );

        return $response;
    }

    protected function formatModel(string $model): string
    {
        return str_starts_with($model, 'models/') ? $model : "models/{$model}";
    }

    /**
     * @return array{parts: array<int, array<string, mixed>>}
     */
    protected function mapContent(Content $content): array
    {
        return [
            'parts' => array_map(
                $this->mapPart(...),
                $content->parts(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapPart(Media|Text $part): array
    {
        return match (true) {
            $part instanceof Text => ['text' => $part->text],
            $part instanceof Image => (new ImageMapper($part))->toPayload(),
            $part instanceof Document => (new DocumentMapper($part))->toPayload(),
            $part instanceof Audio, $part instanceof Video => (new AudioVideoMapper($part))->toPayload(),
            default => throw new InvalidArgumentException('Unsupported embeddings content part: '.$part::class),
        };
    }
}
