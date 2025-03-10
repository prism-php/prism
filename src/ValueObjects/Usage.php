<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

readonly class Usage
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public ?int $cacheWriteInputTokens = null,
        public ?int $cacheReadInputTokens = null
    ) {}
}
