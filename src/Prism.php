<?php

declare(strict_types=1);

namespace EchoLabs\Prism;

use EchoLabs\Prism\Contracts\Provider;
use EchoLabs\Prism\Embeddings\Generator as EmbeddingsGenerator;
use EchoLabs\Prism\Enums\Provider as ProviderEnum;
use EchoLabs\Prism\Providers\ProviderResponse;
use EchoLabs\Prism\Structured\PendingRequest as PendingStructuredRequest;
use EchoLabs\Prism\Testing\PrismFake;
use EchoLabs\Prism\Text\PendingRequest as PendingTextRequest;

class Prism
{
    /**
     * @param  array<int, ProviderResponse>  $responses
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

    public static function embeddings(): \EchoLabs\Prism\Embeddings\Generator
    {
        return new EmbeddingsGenerator;
    }
}
