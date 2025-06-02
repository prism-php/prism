<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI;

use Generator;
use Prism\Prism\Concerns\InitializesClient;
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
    use InitializesClient;

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
     * @return array<string, string>
     */
    protected function getHeaders(): array
    {
        return array_filter([
            'OpenAI-Organization' => $this->organization,
            'OpenAI-Project' => $this->project,
        ]);
    }

    protected function getToken(): string
    {
        return $this->apiKey;
    }
}
