<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Azure\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        return new EmbeddingsResponse(
            embeddings: array_map(fn (array $item): Embedding => Embedding::fromArray($item['embedding']), data_get($data, 'data', [])),
            usage: new EmbeddingsUsage(data_get($data, 'usage.total_tokens')),
            meta: new Meta(
                id: '',
                model: data_get($data, 'model', ''),
            ),
        );
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this->client->post(
            'embeddings',
            [
                'input' => $request->inputs(),
                ...($request->providerOptions() ?? []),
            ]
        );

        return $response;
    }

    protected function validateResponse(Response $response): void
    {
        if ($response->json() === null) {
            throw PrismException::providerResponseError('Azure Error: Empty embeddings response');
        }

        if ($response->json('error')) {
            throw PrismException::providerResponseError(
                sprintf('Azure Error: %s', data_get($response->json(), 'error.message', 'Unknown error'))
            );
        }
    }
}
