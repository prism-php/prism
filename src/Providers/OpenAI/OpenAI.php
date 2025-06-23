<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Enums\Provider as ProviderName;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Images\Request as ImagesRequest;
use Prism\Prism\Images\Response as ImagesResponse;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessesRateLimits;
use Prism\Prism\Providers\OpenAI\Handlers\Embeddings;
use Prism\Prism\Providers\OpenAI\Handlers\Images;
use Prism\Prism\Providers\OpenAI\Handlers\Stream;
use Prism\Prism\Providers\OpenAI\Handlers\Structured;
use Prism\Prism\Providers\OpenAI\Handlers\Text;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Throwable;

class OpenAI extends Provider
{
    use InitializesClient, ProcessesRateLimits;

    public function __construct(
        #[\SensitiveParameter] readonly public string $apiKey,
        readonly public string $url,
        readonly public ?string $organization,
        readonly public ?string $project,
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
    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        $handler = new Embeddings($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function images(ImagesRequest $request): ImagesResponse
    {
        $handler = new Images($this->client(
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

    public function handleRequestExceptions(string $model, Throwable $e): never
    {
        if ($e instanceof PrismException) {
            throw $e;
        }

        if (! $e instanceof RequestException) {
            throw PrismException::providerRequestError($model, $e);
        }

        match ($e->response->getStatusCode()) {
            429 => throw PrismRateLimitedException::make(
                rateLimits: $this->processRateLimits($e->response),
                retryAfter: $e->response->header('retry-after') === ''
                    ? null
                    : (int) $e->response->header('retry-after'),
            ),
            529 => throw PrismProviderOverloadedException::make(ProviderName::Groq),
            413 => throw PrismRequestTooLargeException::make(ProviderName::Groq),
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
                'OpenAI-Organization' => $this->organization,
                'OpenAI-Project' => $this->project,
            ]))
            ->when($this->apiKey, fn ($client) => $client->withToken($this->apiKey))
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? $this->url);
    }
}
