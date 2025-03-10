<?php

namespace Prism\Prism\ValueObjects;

use Carbon\Carbon;

class ProviderRateLimit
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $limit = null,
        public readonly ?int $remaining = null,
        public readonly ?Carbon $resetsAt = null
    ) {}
}
