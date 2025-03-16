<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\ValueObjects;

class SearchGrounding
{
    public function __construct(
        public readonly string $title,
        public readonly string $uri,
        public readonly float $confidence
    ) {}
}
