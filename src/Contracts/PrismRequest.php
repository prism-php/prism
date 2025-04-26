<?php

declare(strict_types=1);

namespace Prism\Prism\Contracts;

interface PrismRequest
{
    /**
     * @param  class-string  $classString
     */
    public function is(string $classString): bool;

    public function model(): string;

    /**
     * @param  array<string, mixed>  $options
     */
    public function withProviderOptions(array $options = []): self;

    public function providerOptions(?string $valuePath = null): mixed;
}
