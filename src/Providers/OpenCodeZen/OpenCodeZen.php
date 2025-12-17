<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenCodeZen;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Enums\Provider as ProviderName;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\OpenCodeZen\Handlers\Stream;
use Prism\Prism\Providers\OpenCodeZen\Handlers\Structured;
use Prism\Prism\Providers\OpenCodeZen\Handlers\Text;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

class OpenCodeZen extends Provider
{
    use InitializesClient;

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $url,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Text($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

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
    public function stream(TextRequest $request): Generator
    {
        $handler = new Stream($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        $statusCode = $e->response->getStatusCode();
        $responseData = $e->response->json();
        $errorMessage = data_get($responseData, 'error.message', 'Unknown error');

        match ($statusCode) {
            400 => throw PrismException::providerResponseError(
                sprintf('OpenCodeZen Bad Request: %s', $errorMessage)
            ),
            401 => throw PrismException::providerResponseError(
                sprintf('OpenCodeZen Authentication Error: %s', $errorMessage)
            ),
            402 => throw PrismException::providerResponseError(
                sprintf('OpenCodeZen Insufficient Credits: %s', $errorMessage)
            ),
            403 => throw PrismException::providerResponseError(
                sprintf('OpenCodeZen Moderation Error: %s', $errorMessage)
            ),
            408 => throw PrismException::providerResponseError(
                sprintf('OpenCodeZen Request Timeout: %s', $errorMessage)
            ),
            413 => throw PrismRequestTooLargeException::make(ProviderName::OpenCodeZen),
            429 => throw PrismRateLimitedException::make(
                rateLimits: [],
                retryAfter: $e->response->hasHeader('retry-after')
                    ? (int) $e->response->header('retry-after')
                    : null
            ),
            502 => throw PrismException::providerResponseError(
                sprintf('OpenCodeZen Model Error: %s', $errorMessage)
            ),
            503 => throw PrismProviderOverloadedException::make(ProviderName::OpenCodeZen),
            default => throw PrismException::providerRequestError($model, $e),
        };
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
            ->baseUrl($baseUrl ?? $this->url);
    }
}
