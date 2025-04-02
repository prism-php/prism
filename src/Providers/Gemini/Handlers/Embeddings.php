<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Throwable;

class Embeddings
{
    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): EmbeddingsResponse
    {
        if (count($request->inputs()) > 1) {
            throw new PrismException('Gemini Error: Prism currently only supports one input at a time with Gemini.');
        }

        try {
            $response = $this->sendRequest($request);
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }

        if ($response->getStatusCode() === 429) {
            throw new PrismRateLimitedException([]);
        }

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
        );
    }

    protected function sendRequest(Request $request): Response
    {
        return $this->client->post(
            "{$request->model()}:embedContent",
            [
                'model' => $request->model(),
                'content' => [
                    'parts' => [
                        ['text' => $request->inputs()[0]],
                    ],
                ],
            ]
        );
    }
}
