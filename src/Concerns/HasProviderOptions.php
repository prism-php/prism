<?php

namespace Prism\Prism\Concerns;

use Prism\Prism\Enums\Provider;

trait HasProviderOptions
{
    /** @var array<string, array<string, mixed>> */
    protected array $providerOptions = [];

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withProviderOptions(string|Provider $provider, array $meta): self
    {
        $this->providerOptions[is_string($provider) ? $provider : $provider->value] = $meta;

        return $this;
    }

    public function providerOptions(string|Provider $provider, ?string $valuePath = null): mixed
    {
        $providerOptions = data_get(
            $this->providerOptions,
            is_string($provider) ? $provider : $provider->value,
            []
        );

        if ($valuePath === null) {
            return $providerOptions;
        }

        return data_get($providerOptions, $valuePath, null);
    }
}
