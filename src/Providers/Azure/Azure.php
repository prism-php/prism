<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Azure;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Enums\Provider as ProviderName;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\Azure\Handlers\Embeddings;
use Prism\Prism\Providers\Azure\Handlers\Stream;
use Prism\Prism\Providers\Azure\Handlers\Structured;
use Prism\Prism\Providers\Azure\Handlers\Text;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

class Azure extends Provider
{
    use InitializesClient;

    public function __construct(
        public readonly string $url,
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $apiVersion,
        public readonly ?string $deploymentName = null,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Text($this->client(
            $request->clientOptions(),
            $request->clientRetry(),
            $this->buildUrl($request->model())
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $handler = new Structured($this->client(
            $request->clientOptions(),
            $request->clientRetry(),
            $this->buildUrl($request->model())
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        $handler = new Embeddings($this->client(
            $request->clientOptions(),
            $request->clientRetry(),
            $this->buildUrl($request->model())
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new Stream($this->client(
            $request->clientOptions(),
            $request->clientRetry(),
            $this->buildUrl($request->model())
        ));

        return $handler->handle($request);
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        $statusCode = $e->response->getStatusCode();
        $responseData = $e->response->json();
        $errorMessage = data_get($responseData, 'error.message', 'Unknown error');

        match ($statusCode) {
            429 => throw PrismRateLimitedException::make(
                rateLimits: [],
                retryAfter: (int) $e->response->header('retry-after')
            ),
            413 => throw PrismRequestTooLargeException::make(ProviderName::Azure),
            400, 404 => throw PrismException::providerResponseError(
                sprintf('Azure Error: %s', $errorMessage)
            ),
            default => throw PrismException::providerRequestError($model, $e),
        };
    }

    /**
     * Build the URL for the Azure deployment.
     * Supports both Azure OpenAI and Azure AI Model Inference endpoints.
     */
    protected function buildUrl(string $model): string
    {
        // If URL already contains the full path, use it directly
        if (str_contains($this->url, '/chat/completions') || str_contains($this->url, '/embeddings')) {
            return $this->url;
        }

        // Use deployment name from config, or model name as fallback
        $deployment = $this->deploymentName ?: $model;

        // If URL already contains 'deployments', append the deployment
        if (str_contains($this->url, '/openai/deployments')) {
            return rtrim($this->url, '/')."/{$deployment}";
        }

        // Build standard Azure OpenAI URL pattern
        return rtrim($this->url, '/')."/openai/deployments/{$deployment}";
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        return $this->baseClient()
            ->withHeaders([
                'api-key' => $this->apiKey,
            ])
            ->withQueryParameters([
                'api-version' => $this->apiVersion,
            ])
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? $this->url);
    }
}
