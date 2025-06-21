<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\Provider;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Gemini\Handlers\Cache;
use Prism\Prism\Providers\Gemini\Handlers\Embeddings;
use Prism\Prism\Providers\Gemini\Handlers\Stream;
use Prism\Prism\Providers\Gemini\Handlers\Structured;
use Prism\Prism\Providers\Gemini\Handlers\Text;
use Prism\Prism\Providers\Gemini\ValueObjects\GeminiCachedObject;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

readonly class Gemini implements Provider
{
    public function __construct(
        #[\SensitiveParameter] public string $apiKey,
        public string $url,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Text(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->apiKey
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
            $this->apiKey
        );

        return $handler->handle($request);
    }

    /**
     * @param  Message[]  $messages
     * @param  array<SystemMessage|string>  $systemPrompts
     */
    public function cache(string $model, array $messages = [], array $systemPrompts = [], ?int $ttl = null): GeminiCachedObject
    {
        if ($messages === [] && $systemPrompts === []) {
            throw new PrismException('At least one message or system prompt must be provided');
        }

        $systemPrompts = array_map(
            fn ($prompt): SystemMessage => $prompt instanceof SystemMessage ? $prompt : new SystemMessage($prompt),
            $systemPrompts
        );

        $handler = new Cache(
            client: $this->client(
                baseUrl: 'https://generativelanguage.googleapis.com/v1beta'
            ),
            model: $model,
            messages: $messages,
            systemPrompts: $systemPrompts,
            ttl: $ttl
        );

        return $handler->handle();
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        $baseUrl ??= $this->url;

        $client = Http::withOptions($options)
            ->withHeaders([
                'x-goog-api-key' => $this->apiKey,
            ])
            ->baseUrl($baseUrl);

        if ($retry !== []) {
            return $client->retry(...$retry);
        }

        return $client;
    }
}
