<?php

declare(strict_types=1);

namespace Prism\Prism;

use Prism\Prism\Contracts\Provider;
use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Prism\Prism\Text\Response as TextResponse;

class Prism
{
    /**
     * @param  array<int, TextResponse|StructuredResponse|EmbeddingResponse>  $responses
     */
    public static function fake(array $responses = []): PrismFake
    {
        $fake = new PrismFake($responses);

        app()->instance(PrismManager::class, new class($fake) extends PrismManager
        {
            public function __construct(
                private readonly PrismFake $fake
            ) {}

            public function resolve(ProviderEnum|string $name, array $providerConfig = []): Provider
            {
                $this->fake->setProviderConfig($providerConfig);

                return $this->fake;
            }
        });

        return $fake;
    }

    public static function text(): PendingTextRequest
    {
        return new PendingTextRequest;
    }

    public static function structured(): PendingStructuredRequest
    {
        return new PendingStructuredRequest;
    }

    public static function embeddings(): PendingEmbeddingRequest
    {
        return new PendingEmbeddingRequest;
    }

    /**
     * @param  array<string,mixed>  $providerConfig
     */
    public static function provider(ProviderEnum|string $name, array $providerConfig = []): Provider
    {
        return app(PrismManager::class)->resolve($name, $providerConfig);
    }
}
