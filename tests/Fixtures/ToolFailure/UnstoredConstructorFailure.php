<?php

declare(strict_types=1);

namespace Tests\Fixtures\ToolFailure;

use RuntimeException;

class UnstoredConstructorFailure extends RuntimeException
{
    public function __construct(string $reason, int $retryAfter)
    {
        parent::__construct($reason.':'.$retryAfter);
    }
}
