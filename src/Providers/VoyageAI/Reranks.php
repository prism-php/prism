<?php

namespace Prism\Prism\Providers\VoyageAI;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\VoyageAI\ValueObjects\Rerank;
use Prism\Prism\Providers\VoyageAI\ValueObjects\ReranksUsage;
use Prism\Prism\Rerank\Request as RerankRequest;
use Prism\Prism\Rerank\Response as RerankResponse;
use Prism\Prism\ValueObjects\Meta;

class Reranks
{
    protected RerankRequest $request;

    protected Response $httpResponse;

    public function __construct(protected PendingRequest $client) {}

    public function handle(RerankRequest $request): RerankResponse
    {
        $this->request = $request;

        $this->sendRequest();

        $this->validateResponse();

        $data = $this->httpResponse->json();

        /** @var array<array{relevance_score: float|int, index: int, document?: string}> $items */
        $items = data_get($data, 'data', []);

        return new RerankResponse(
            reranks: array_map(
                fn (array $item): Rerank => Rerank::fromArray($item, $this->request),
                $items
            ),
            usage: new ReranksUsage(
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
        $providerOptions = $this->request->providerOptions();

        try {
            $this->httpResponse = $this->client->post('rerank', Arr::whereNotNull([
                'model' => $this->request->model(),
                'query' => $this->request->query(),
                'documents' => $this->request->documents(),
                'top_k' => $providerOptions['top_k'] ?? null,
                'return_documents' => $providerOptions['return_documents'] ?? null,
                'truncation' => $providerOptions['truncation'] ?? null,
            ]));
        } catch (\Exception $e) {
            throw PrismException::providerRequestError($this->request->model(), $e);
        }
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
