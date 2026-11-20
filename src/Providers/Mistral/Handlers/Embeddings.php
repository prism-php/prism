<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Providers\Mistral\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\Mistral\Concerns\ValidatesResponse;
use Prism\Prism\Support\EmbeddingDecoder;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;

class Embeddings
{
    use ProcessRateLimits, ValidatesResponse;

    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): EmbeddingsResponse
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        return new EmbeddingsResponse(
            embeddings: array_map(fn (array $item): Embedding => EmbeddingDecoder::decode($item['embedding'] ?? null), data_get($data, 'data', [])),
            usage: new EmbeddingsUsage(data_get($data, 'usage.total_tokens')),
            meta: new Meta(
                id: data_get($data, 'id', ''),
                model: data_get($data, 'model', ''),
                rateLimits: $this->processRateLimits($response)
            ),
            raw: $data,
        );
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this->client->post(
            'embeddings',
            Arr::whereNotNull([
                'model' => $request->model(),
                'input' => $request->inputs(),
                'encoding_format' => $request->providerOptions('encoding_format'),
            ])
        );

        return $response;
    }
}
