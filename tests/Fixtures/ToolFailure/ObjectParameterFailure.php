<?php

declare(strict_types=1);

namespace Tests\Fixtures\ToolFailure;

use RuntimeException;

class ObjectParameterFailure extends RuntimeException
{
    public function __construct(public readonly array|object $details)
    {
        parent::__construct(json_encode($details, JSON_THROW_ON_ERROR));
    }
}
