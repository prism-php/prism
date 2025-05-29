<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\FireworksAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\FireworksAI\Concerns\ValidateResponse;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Throwable;

class Embeddings
{
    use ValidateResponse;

    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): Response
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        return new Response(
            embeddings: array_map(fn (array $embedding): Embedding => new Embedding(
                embedding: data_get($embedding, 'embedding'),
            ), data_get($data, 'data', [])),
            usage: new EmbeddingsUsage(
                tokens: data_get($data, 'usage.total_tokens'),
            ),
            meta: new Meta(
                id: '',
                model: data_get($data, 'model', ''),
            ),
        );
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        try {
            $body = [
                'model' => $request->model(),
                'input' => $request->inputs(),
                'encoding_format' => 'float',
            ];

            if ($dimensions = $request->providerOptions('dimensions')) {
                $body['dimensions'] = $dimensions;
            }

            return $this->client->post(
                'embeddings',
                Arr::whereNotNull($body)
            );
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }
    }
}
