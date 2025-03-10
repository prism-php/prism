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

    public function using(string|ProviderEnum $provider, string $model): self
    {
        $this->providerKey = is_string($provider) ? $provider : $provider->value;

        $this->provider = resolve(PrismManager::class)->resolve($this->providerKey);

        $this->model = $model;

        return $this;
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
}
