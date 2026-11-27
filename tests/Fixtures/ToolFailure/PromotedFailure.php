<?php

declare(strict_types=1);

namespace Tests\Fixtures\ToolFailure;

use RuntimeException;

class PromotedFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $retryAfter)
    {
        parent::__construct($reason.':'.$retryAfter);
    }
}
