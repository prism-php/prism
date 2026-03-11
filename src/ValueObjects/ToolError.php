<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

readonly class ToolError
{
    public function __construct(
        public string $message,
    ) {}
}
