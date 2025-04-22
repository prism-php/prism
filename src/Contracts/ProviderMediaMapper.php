<?php

namespace Prism\Prism\Contracts;

use InvalidArgumentException;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\Support\Media;

abstract class ProviderMediaMapper
{
    public function __construct(public readonly Media $media)
    {
        if ($this->validateMedia() === false) {
            $calledClass = static::class;

            throw new InvalidArgumentException("The {$this->providerName()} provider does not support the specified `$calledClass`. Pleae consult the Prism documentation for supported `$calledClass` types.");
        }
    }

    /**
     * @return array<string,mixed>
     */
    abstract public function toPayload(): array;

    abstract protected function provider(): string|Provider;

    abstract protected function validateMedia(): bool;

    protected function providerName(): string
    {
        return $this->provider() instanceof Provider ? $this->provider()->value : $this->provider();
    }
}
