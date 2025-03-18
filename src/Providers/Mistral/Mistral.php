<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Contracts\Provider;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Mistral\Handlers\Embeddings;
use Prism\Prism\Providers\Mistral\Handlers\OCR;
use Prism\Prism\Providers\Mistral\Handlers\Structured;
use Prism\Prism\Providers\Mistral\Handlers\Text;
use Prism\Prism\Providers\Mistral\ValueObjects\OCRResponse;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

readonly class Mistral implements Provider
{
    public function __construct(
        #[\SensitiveParameter] public string $apiKey,
        public string $url,
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

    public function ocr(string $model, string $documentUrl): OCRResponse
    {
        // todo validation on document value object that is from url

        $handler = new OCR(
            client: $this->client(),
            model: $model,
            documentUrl: $documentUrl
        );

        return $handler->handle();
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        throw PrismException::unsupportedProviderAction(__METHOD__, class_basename($this));
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(array $options = [], array $retry = []): PendingRequest
    {
        $client = Http::withHeaders(array_filter([
            'Authorization' => $this->apiKey !== '' && $this->apiKey !== '0' ? sprintf('Bearer %s', $this->apiKey) : null,
        ]))
            ->withOptions($options)
            ->baseUrl($this->url);

        if ($retry !== []) {
            return $client->retry(...$retry);
        }

        return $client;
    }
}
