<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\VoyageAI;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Prism\Concerns\HasTelemetry;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;

class Embeddings
{
    use HasTelemetry;

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
                tokens: data_get($data, 'usage.total_tokens', null),
            ),
            meta: new Meta(
                id: '',
                model: data_get($data, 'model', ''),
            ),
        );
    }

    protected function sendRequest(): void
    {
        $this->httpResponse = $this->trace('voyageai.http', [
            'http.method' => 'POST',
            'voyageai.endpoint' => 'embeddings',
            'prism.provider' => 'voyageai',
            'prism.model' => $this->request->model(),
            'prism.request_type' => 'embeddings',
        ], function () {
            $providerOptions = $this->request->providerOptions();

            try {
                return $this->client->post('embeddings', array_filter([
                    'model' => $this->request->model(),
                    'input' => $this->request->inputs(),
                    'input_type' => $providerOptions['inputType'] ?? null,
                    'truncation' => $providerOptions['truncation'] ?? null,
                ]));
            } catch (\Exception $e) {
                throw PrismException::providerRequestError($this->request->model(), $e);
            }
        });
    }

    protected function validateResponse(): void
    {
        if ($this->httpResponse->getStatusCode() === 429) {
            throw new PrismRateLimitedException([]);
        }

        $data = $this->httpResponse->json();

        if (! $data || data_get($data, 'detail')) {
            throw PrismException::providerResponseError('Voyage AI error: '.data_get($data, 'detail'));
        }
    }
}
