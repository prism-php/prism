<?php

declare(strict_types=1);

namespace Prism\Prism\Contracts;

use Prism\Prism\Enums\Provider;

interface PrismRequest
{
    /**
     * @param  class-string  $classString
     */
    public function is(string $classString): bool;

    public function model(): string;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withProviderMeta(string|Provider $provider, array $meta): self;

    public function providerMeta(string|Provider $provider, ?string $valuePath = null): mixed;
}
