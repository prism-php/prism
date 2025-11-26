<?php

namespace Prism\Prism\ValueObjects;

use Illuminate\Support\Arr;
use Prism\Prism\Enums\Provider;

class ProviderOption
{
    public function __construct(
        public string $key,
        public mixed $value,
        public ?Provider $provider = null,
    ) {}

    public function getValue(?string $valuePath = null): mixed
    {
        if (is_null($valuePath) || ! Arr::accessible($this->value)) {
            return $this->value;
        }

        return Arr::get($this->value, $valuePath);
    }

    public function acceptsProvider(Provider|string|null $provider): bool
    {
        if (is_null($provider) || is_null($this->provider)) {
            return true;
        }

        $providerKey = $provider instanceof Provider
            ? $provider->value
            : $provider;

        return $this->provider->value === $providerKey;
    }
}
