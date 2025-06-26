<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

use Prism\Prism\ValueObjects\Usage;

readonly class UsageChunk
{
    public function __construct(
        public Usage $usage
    ) {}
}
