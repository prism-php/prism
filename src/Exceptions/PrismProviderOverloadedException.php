<?php

declare(strict_types=1);

namespace Prism\Prism\Exceptions;

use Prism\Prism\Enums\Provider;

class PrismProviderOverloadedException extends PrismException
{
    public function __construct(string|Provider $provider)
    {
        $provider = is_string($provider) ? $provider : $provider->value;

        parent::__construct("Prism provider $provider is overloaded.");
    }

    public static function make(string|Provider $provider): self
    {
        return new self($provider);
    }
}
