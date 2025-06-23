<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Enums\Provider as ProviderName;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\Anthropic\Concerns\ProcessesRateLimits;
use Prism\Prism\Providers\Anthropic\Handlers\Stream;
use Prism\Prism\Providers\Anthropic\Handlers\Structured;
use Prism\Prism\Providers\Anthropic\Handlers\Text;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

class Anthropic extends Provider
{
    use InitializesClient, ProcessesRateLimits;

    public function __construct(
        #[\SensitiveParameter] readonly public string $apiKey,
        readonly public string $apiVersion,
        readonly public ?string $betaFeatures = null
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Text(
            $this->client(
                $request->clientOptions(),
                $request->clientRetry()
            ),
            $request
        );

        return $handler->handle();
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $handler = new Structured(
            $this->client(
                $request->clientOptions(),
                $request->clientRetry()
            ),
            $request
        );

        return $handler->handle();
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
        match ($e->response->getStatusCode()) {
            429 => throw PrismRateLimitedException::make(
                rateLimits: $this->processRateLimits($e->response),
                retryAfter: $e->response->hasHeader('retry-after')
                    ? (int) $e->response->getHeader('retry-after')[0]
                    : null
            ),
            529 => throw PrismProviderOverloadedException::make(ProviderName::Anthropic),
            413 => throw PrismRequestTooLargeException::make(ProviderName::Anthropic),
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
            ->withHeaders(array_filter([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->apiVersion,
                'anthropic-beta' => $this->betaFeatures,
            ]))
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? 'https://api.anthropic.com/v1');
    }
}
