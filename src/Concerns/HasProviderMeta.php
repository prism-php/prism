<?php

namespace Prism\Prism\Concerns;

use Prism\Prism\Enums\Provider;

trait HasProviderMeta
{
    /** @var array<string, array<string, mixed>> */
    protected array $providerMeta = [];

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withProviderMeta(string|Provider $provider, array $meta): self
    {
        $this->providerMeta[is_string($provider) ? $provider : $provider->value] = $meta;

        return $this;
    }

    public function providerMeta(string|Provider $provider, ?string $valuePath = null): mixed
    {
        $providerMeta = data_get(
            $this->providerMeta,
            is_string($provider) ? $provider : $provider->value,
            []
        );

        return data_get($providerMeta, $valuePath, $providerMeta);
    }
}
