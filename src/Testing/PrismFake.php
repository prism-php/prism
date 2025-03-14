<?php

declare(strict_types=1);

namespace Prism\Prism\Testing;

use Closure;
use Exception;
use Generator;
use PHPUnit\Framework\Assert as PHPUnit;
use Prism\Prism\Contracts\Provider;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class PrismFake implements Provider
{
    protected int $responseSequence = 0;

    /** @var array<int, StructuredRequest|TextRequest|EmbeddingRequest> */
    protected array $recorded = [];

    /** @var array<string, mixed> */
    protected array $providerConfig = [];

    /**
     * @param  array<int, TextResponse|StructuredResponse|EmbeddingResponse>  $responses
     */
    public function __construct(protected array $responses = []) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $this->recorded[] = $request;

        return $this->nextTextResponse() ?? new TextResponse(
            steps: collect([]),
            responseMessages: collect([]),
            text: '',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(0, 0),
            meta: new Meta('fake', 'fake'),
            messages: collect([]),
            additionalContent: [],
        );
    }

    #[\Override]
    public function embeddings(EmbeddingRequest $request): EmbeddingResponse
    {
        $this->recorded[] = $request;

        return $this->nextEmbeddingResponse() ?? new EmbeddingResponse(
            embeddings: [],
            usage: new EmbeddingsUsage(10),
            meta: new Meta('fake-id', 'fake-model'),
        );
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $this->recorded[] = $request;

        return $this->nextStructuredResponse() ?? new StructuredResponse(
            steps: collect([]),
            responseMessages: collect([]),
            text: '',
            structured: [],
            finishReason: FinishReason::Stop,
            usage: new Usage(0, 0),
            meta: new Meta('fake', 'fake'),
            additionalContent: [],
        );
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        throw PrismException::unsupportedProviderAction(__METHOD__, class_basename($this));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function setProviderConfig(array $config): void
    {
        $this->providerConfig = $config;
    }

    /**
     * @param  Closure(array<int, StructuredRequest|TextRequest|EmbeddingRequest>):void  $fn
     */
    public function assertRequest(Closure $fn): void
    {
        $fn($this->recorded);
    }

    public function assertPrompt(string $prompt): void
    {
        $prompts = collect($this->recorded)
            ->flatten()
            ->map(fn ($response) => $response->prompt());

        PHPUnit::assertTrue(
            $prompts->contains($prompt),
            "Could not find the prompt {$prompt} in the recorded requests"
        );
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    public function assertProviderConfig(array $providerConfig): void
    {
        PHPUnit::assertEqualsCanonicalizing(
            $providerConfig,
            $this->providerConfig
        );
    }

    /**
     * Assert number of calls made
     */
    public function assertCallCount(int $expectedCount): void
    {
        $actualCount = count($this->recorded ?? []);

        PHPUnit::assertSame($expectedCount, $actualCount, "Expected {$expectedCount} calls, got {$actualCount}");
    }

    protected function nextTextResponse(): ?TextResponse
    {
        if (! isset($this->responses)) {
            return null;
        }

        /** @var array<int, TextResponse> $responses */
        $responses = $this->responses;
        $sequence = $this->responseSequence;

        if (! isset($responses[$sequence])) {
            throw new Exception('Could not find a response for the request');
        }

        $this->responseSequence++;

        return $responses[$sequence];
    }

    protected function nextStructuredResponse(): ?StructuredResponse
    {
        if (! isset($this->responses)) {
            return null;
        }

        /** @var array<int, StructuredResponse> $responses */
        $responses = $this->responses;
        $sequence = $this->responseSequence;

        if (! isset($responses[$sequence])) {
            throw new Exception('Could not find a response for the request');
        }

        $this->responseSequence++;

        return $responses[$sequence];
    }

    protected function nextEmbeddingResponse(): ?EmbeddingResponse
    {
        if (! isset($this->responses)) {
            return null;
        }

        /** @var EmbeddingResponse[] $responses */
        $responses = $this->responses;
        $sequence = $this->responseSequence;

        if (! isset($responses[$sequence])) {
            throw new Exception('Could not find a response for the request');
        }

        $this->responseSequence++;

        return $responses[$sequence];
    }
}
