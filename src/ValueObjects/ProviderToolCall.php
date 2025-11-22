<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

readonly class ProviderToolCall
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $status,
        public array $data,
    ) {}
}
