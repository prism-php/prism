<?php

declare(strict_types=1);

namespace Prism\Prism\Rerank;

use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\VoyageAI\VoyageAI;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresProviders;
    use HasProviderOptions;

    protected string $query = '';

    /** @var array<string> */
    protected array $documents = [];

    /**
     * Set the query for reranking
     */
    public function withQuery(string $query): self
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Add multiple documents for reranking
     *
     * @param  array<string>  $documents
     */
    public function withDocuments(array $documents): self
    {
        $this->documents = $documents;

        return $this;
    }

    public function asRerank(): \Prism\Prism\Rerank\Response
    {
        if ($this->query === '') {
            throw new PrismException('Query is required for reranking');
        }

        if ($this->documents === []) {
            throw new PrismException('At least one document is required for reranking');
        }

        /** @var VoyageAI */
        $provider = $this->provider;

        return $provider->rerank($this->toRequest());
    }

    protected function toRequest(): Request
    {
        return new Request(
            model: $this->model,
            query: $this->query,
            documents: $this->documents,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            providerOptions: $this->providerOptions
        );
    }
}
