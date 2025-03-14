<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI;

use Closure;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Contracts\Provider;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Providers\OpenAI\Handlers\Embeddings;
use Prism\Prism\Providers\OpenAI\Handlers\Stream;
use Prism\Prism\Providers\OpenAI\Handlers\Structured;
use Prism\Prism\Providers\OpenAI\Handlers\Text;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

readonly class OpenAI implements Provider
{
    public function __construct(
        #[\SensitiveParameter] public string $apiKey,
        public string $url,
        public ?string $organization,
        public ?string $project,
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
    public function stream(TextRequest $request): Generator
    {
        $handler = new Stream($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}  $retry
     */
    protected function client(array $options, array $retry): PendingRequest
    {
        return Http::withHeaders(array_filter([
            'Authorization' => $this->apiKey !== '' && $this->apiKey !== '0' ? sprintf('Bearer %s', $this->apiKey) : null,
            'OpenAI-Organization' => $this->organization,
            'OpenAI-Project' => $this->project,
        ]))
            ->withOptions($options)
            ->retry(...$retry)
            ->baseUrl($this->url);
    }
}
