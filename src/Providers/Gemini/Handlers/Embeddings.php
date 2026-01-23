<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;

class Embeddings
{
    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): EmbeddingsResponse
    {
        if (count($request->inputs()) > 1) {
            throw new PrismException('Gemini Error: Prism currently only supports one input at a time with Gemini.');
        }

        $response = $this->sendRequest($request);

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

    protected function sendRequest(Request $request): Response
    {
        $providerOptions = $request->providerOptions();

        /** @var Response $response */
        $response = $this->client->post(
            "{$request->model()}:embedContent",
            Arr::whereNotNull([
                'model' => $request->model(),
                'content' => [
                    'parts' => [
                        ['text' => $request->inputs()[0]],
                    ],
                ],
                'title' => $providerOptions['title'] ?? null,
                'taskType' => $providerOptions['taskType'] ?? null,
                'outputDimensionality' => $providerOptions['outputDimensionality'] ?? null,
            ])
        );

        return $response;
    }
}
