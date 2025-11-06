<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

class ProviderTool
{
    /**
     * @param  array<string,mixed>  $options
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $name = null,
        public readonly array $options = [],
    ) {}
}
