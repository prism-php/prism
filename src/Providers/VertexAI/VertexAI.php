<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\VertexAI;

use Generator;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Providers\VertexAI\Handlers\Embeddings;
use Prism\Prism\Providers\VertexAI\Handlers\Stream;
use Prism\Prism\Providers\VertexAI\Handlers\Structured;
use Prism\Prism\Providers\VertexAI\Handlers\Text;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

class VertexAI extends Provider
{
    use InitializesClient;

    public function __construct(
        public readonly string $projectId,
        public readonly string $location,
        #[\SensitiveParameter] public readonly ?string $apiKey = null,
        #[\SensitiveParameter] public readonly ?string $credentials = null,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Text(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->apiKey ?? ''
        );

        return $handler->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $handler = new Structured($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function embeddings(EmbeddingRequest $request): EmbeddingResponse
    {
        $handler = new Embeddings($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new Stream(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->apiKey ?? ''
        );

        return $handler->handle($request);
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        match ($e->response->getStatusCode()) {
            429 => throw PrismRateLimitedException::make([]),
            503 => throw PrismProviderOverloadedException::make(class_basename($this)),
            default => $this->handleResponseErrors($e),
        };
    }

    protected function handleResponseErrors(RequestException $e): never
    {
        $data = $e->response->json() ?? [];

        throw PrismException::providerRequestErrorWithDetails(
            provider: 'VertexAI',
            statusCode: $e->response->getStatusCode(),
            errorType: data_get($data, 'error.status'),
            errorMessage: data_get($data, 'error.message'),
            previous: $e
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(array $options = [], array $retry = []): PendingRequest
    {
        $baseUrl = sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models',
            $this->location,
            $this->projectId,
            $this->location
        );

        $client = $this->baseClient()
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl);

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $client = $client->withQueryParameters(['key' => $this->apiKey]);
        }

        if ($this->credentials !== null && $this->credentials !== '') {
            return $client->withToken($this->resolveAccessToken());
        }

        return $client;
    }

    protected function resolveAccessToken(): string
    {
        if (! class_exists(ServiceAccountCredentials::class)) {
            throw new PrismException(
                'The google/auth package is required for service account authentication. Install it with: composer require google/auth'
            );
        }

        $path = $this->credentials ?? '';

        if (! is_file($path)) {
            throw new PrismException(
                "Vertex AI credentials file not found: {$path}"
            );
        }

        $credentials = new ServiceAccountCredentials(
            scope: 'https://www.googleapis.com/auth/cloud-platform',
            jsonKey: json_decode((string) file_get_contents($path), true),
        );

        $token = $credentials->fetchAuthToken();

        return $token['access_token'];
    }
}
