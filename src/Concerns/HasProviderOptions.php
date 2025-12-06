<?php

namespace Prism\Prism\Concerns;

use Prism\Prism\ValueObjects\ProviderOption;

trait HasProviderOptions
{
    /** @var array<string, ProviderOption> */
    protected array $providerOptions = [];

    /**
     * @param  array<int|string, mixed>  $options
     */
    public function withProviderOptions(array $options = []): self
    {
        $options = collect($options)->mapWithKeys(function ($value, $key): array {
            $value = $value instanceof ProviderOption
                ? $value
                : new ProviderOption($key, $value);

            return [$value->key => $value];
        });

        $this->providerOptions = $options->toArray();

        return $this;
    }

    public function providerOptions(?string $valuePath = null): mixed
    {
        if ($valuePath === null) {
            return array_map(
                fn (ProviderOption $option): mixed => $option->getValue(),
                $this->getProviderOptions(),
            );
        }

        $paths = explode('.', $valuePath, 2);
        $option = data_get($this->getProviderOptions(), array_first($paths));

        if (is_null($option)) {
            return null;
        }

        return count($paths) > 1
            ? $option->getValue(array_last($paths))
            : $option->getValue();
    }

    private function getProviderOptions(): array
    {
        return array_filter(
            $this->providerOptions,
            fn (ProviderOption $option): bool => $option->acceptsProvider(
                $this->providerKey ?? null,
            ),
        );
    }
}
