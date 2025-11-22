<?php

namespace Prism\Prism\Contracts;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Media\Media;

abstract class ProviderMediaMapper
{
    public function __construct(public readonly Media $media)
    {
        $this->runValidation();
    }

    abstract public function toPayload(): mixed;

    abstract protected function provider(): string|Provider;

    abstract protected function validateMedia(): bool;

    protected function runValidation(): void
    {
        if ($this->validateMedia() === false) {
            $providerName = $this->provider() instanceof Provider ? $this->provider()->value : $this->provider();

            $calledClass = static::class;

            throw new PrismException("The $providerName provider does not support the mediums available in the provided `$calledClass`. Pleae consult the Prism documentation for more information on which mediums the $providerName provider supports.");
        }
    }
}
