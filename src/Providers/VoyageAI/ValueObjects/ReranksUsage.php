<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\VoyageAI\ValueObjects;

readonly class ReranksUsage
{
    public function __construct(
        public ?int $tokens
    ) {}
}
