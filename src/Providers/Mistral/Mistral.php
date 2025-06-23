<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Enums\Provider as ProviderName;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\Mistral\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\Mistral\Handlers\Embeddings;
use Prism\Prism\Providers\Mistral\Handlers\OCR;
use Prism\Prism\Providers\Mistral\Handlers\Stream;
use Prism\Prism\Providers\Mistral\Handlers\Structured;
use Prism\Prism\Providers\Mistral\Handlers\Text;
use Prism\Prism\Providers\Mistral\ValueObjects\OCRResponse;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\Support\Document;

class Mistral extends Provider
{
    use InitializesClient, ProcessRateLimits;

    public function __construct(
        #[\SensitiveParameter] readonly public string $apiKey,
        readonly public string $url,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Text(
            $this->client(
                $request->clientOptions(),
                $request->clientRetry()
            ));

        return $handler->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $handler = new Structured(
            $this->client(
                $request->clientOptions(),
                $request->clientRetry()
            )
        );

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

    /**
     * @throws PrismRateLimitedException
     * @throws PrismException
     */
    public function ocr(string $model, Document $document): OCRResponse
    {
        if (! $document->isUrl()) {
            throw new PrismException('Document must be based on a URL');
        }

        $handler = new OCR(
            client: $this->client([
                'timeout' => 120,
            ]),
            model: $model,
            document: $document
        );

        return $handler->handle();
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new Stream(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->apiKey
        );

        return $handler->handle($request);
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        match ($e->response->getStatusCode()) {
            429 => throw PrismRateLimitedException::make(
                rateLimits: $this->processRateLimits($e->response),
                retryAfter: null
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
            ->when($this->apiKey, fn ($client) => $client->withToken($this->apiKey))
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? $this->url);
    }
}
