<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

readonly class RerankUsage
{
    public function __construct(
        public ?int $tokens
    ) {}
}
