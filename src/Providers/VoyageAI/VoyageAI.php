<?php

namespace Prism\Prism\Providers\VoyageAI;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Providers\Provider;

class VoyageAI extends Provider
{
    use InitializesClient;

    public function __construct(
        #[\SensitiveParameter] protected readonly string $apiKey,
        protected readonly string $baseUrl
    ) {}

    #[\Override]
    public function embeddings(EmbeddingRequest $request): EmbeddingsResponse
    {
        $handler = new Embeddings($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        return $this->baseClient()
            ->when($this->apiKey, fn ($client) => $client->withToken($this->apiKey))
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? $this->baseUrl);
    }
}
