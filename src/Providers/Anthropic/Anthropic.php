<?php

declare(strict_types=1);

namespace PrismPHP\Prism\Providers\Anthropic;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use PrismPHP\Prism\Contracts\Provider;
use PrismPHP\Prism\Embeddings\Request as EmbeddingRequest;
use PrismPHP\Prism\Embeddings\Response as EmbeddingResponse;
use PrismPHP\Prism\Exceptions\PrismException;
use PrismPHP\Prism\Providers\Anthropic\Handlers\Structured;
use PrismPHP\Prism\Providers\Anthropic\Handlers\Text;
use PrismPHP\Prism\Stream\Request as StreamRequest;
use PrismPHP\Prism\Structured\Request as StructuredRequest;
use PrismPHP\Prism\Structured\Response as StructuredResponse;
use PrismPHP\Prism\Text\Request as TextRequest;
use PrismPHP\Prism\Text\Response;

readonly class Anthropic implements Provider
{
    public function __construct(
        #[\SensitiveParameter] public string $apiKey,
        public string $apiVersion,
        public ?string $betaFeatures = null
    ) {}

    #[\Override]
    public function text(TextRequest $request): Response
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
    public function stream(StreamRequest $request): Generator
    {
        throw PrismException::unsupportedProviderAction(__METHOD__, class_basename($this));
    }

    #[\Override]
    public function embeddings(EmbeddingRequest $request): EmbeddingResponse
    {
        throw new \Exception(sprintf('%s does not support embeddings', class_basename($this)));
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(array $options = [], array $retry = []): PendingRequest
    {
        return Http::withHeaders(array_filter([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->apiVersion,
            'anthropic-beta' => $this->betaFeatures,
        ]))
            ->withOptions($options)
            ->retry(...$retry)
            ->baseUrl('https://api.anthropic.com/v1');
    }
}
