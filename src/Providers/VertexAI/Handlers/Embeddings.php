<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\VertexAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Gemini\Handlers\Embeddings as GeminiEmbeddings;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;

class Embeddings extends GeminiEmbeddings
{
    public function __construct(protected PendingRequest $client) {}

    #[\Override]
    public function handle(Request $request): EmbeddingsResponse
    {
        $response = $this->sendRequest($request);

        $data = $response->json();

        if (! isset($data['predictions'][0]['embeddings']['values'])) {
            throw PrismException::providerResponseError(
                'VertexAI Error: Invalid response format or missing embedding data'
            );
        }

        return new EmbeddingsResponse(
            embeddings: [Embedding::fromArray(data_get($data, 'predictions.0.embeddings.values', []))],
            usage: new EmbeddingsUsage(data_get($data, 'predictions.0.embeddings.statistics.token_count', 0)),
            meta: new Meta(
                id: '',
                model: '',
            ),
            raw: $data,
        );
    }

    #[\Override]
    protected function sendRequest(Request $request): Response
    {
        $providerOptions = $request->providerOptions();

        $instance = Arr::whereNotNull([
            'content' => $request->inputs()[0],
            'task_type' => $providerOptions['taskType'] ?? null,
            'title' => $providerOptions['title'] ?? null,
        ]);

        $parameters = Arr::whereNotNull([
            'outputDimensionality' => $providerOptions['outputDimensionality'] ?? null,
            'autoTruncate' => $providerOptions['autoTruncate'] ?? null,
        ]);

        /** @var Response $response */
        $response = $this->client->post(
            "{$request->model()}:predict",
            Arr::whereNotNull([
                'instances' => [$instance],
                'parameters' => $parameters !== [] ? $parameters : null,
            ])
        );

        return $response;
    }
}
