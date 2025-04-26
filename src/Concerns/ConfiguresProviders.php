<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\Contracts\Provider;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\PrismManager;

trait ConfiguresProviders
{
    protected Provider $provider;

    protected string $providerKey;

    protected string $model;

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    public function using(string|ProviderEnum $provider, string $model, array $providerConfig = []): self
    {
        $this->providerKey = is_string($provider) ? $provider : $provider->value;

        $this->model = $model;

        return $this->usingProviderConfig($providerConfig);
    }

    public function provider(): Provider
    {
        return $this->provider;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function usingProviderConfig(array $config): self
    {
        $this->provider = resolve(PrismManager::class)->resolve($this->providerKey, $config);

        return $this;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function providerKey(): string
    {
        return $this->providerKey;
    }

    public function whenProvider(string|ProviderEnum $provider, callable $callback): self
    {
        $providerKey = is_string($provider) ? $provider : $provider->value;

        if ($this->providerKey() === $providerKey) {
            return $callback($this);
        }

        return $this;
    }
}
