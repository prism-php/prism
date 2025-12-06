<?php

namespace Prism\Prism\Providers\VoyageAI;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;

class Embeddings
{
    protected EmbeddingsRequest $request;

    protected Response $httpResponse;

    public function __construct(protected PendingRequest $client) {}

    public function handle(EmbeddingsRequest $request): EmbeddingsResponse
    {
        $this->request = $request;

        $this->sendRequest();

        $this->validateResponse();

        $data = $this->httpResponse->json();

        return new EmbeddingsResponse(
            embeddings: array_map(fn (array $item): Embedding => Embedding::fromArray($item['embedding']), data_get($data, 'data', [])),
            usage: new EmbeddingsUsage(
                tokens: data_get($data, 'usage.total_tokens'),
            ),
            meta: new Meta(
                id: '',
                model: data_get($data, 'model', ''),
            ),
        );
    }

    protected function sendRequest(): void
    {
        $providerOptions = $this->request->providerOptions();

        /** @var Response $response */
        $response = $this->client->post('embeddings', Arr::whereNotNull([
            'model' => $this->request->model(),
            'input' => $this->request->inputs(),
            'input_type' => $providerOptions['inputType'] ?? null,
            'truncation' => $providerOptions['truncation'] ?? null,
        ]));

        $this->httpResponse = $response;
    }

    protected function validateResponse(): void
    {
        $data = $this->httpResponse->json();

        if (! $data || data_get($data, 'detail')) {
            throw PrismException::providerResponseError('Voyage AI error: '.data_get($data, 'detail'));
        }
    }
}
