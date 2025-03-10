<?php

declare(strict_types=1);

namespace Prism\Prism\Exceptions;

use Throwable;

class PrismChunkDecodeException extends PrismException
{
    public function __construct(string $provider, Throwable $previous)
    {
        parent::__construct(
            sprintf('Could not decode stream chunk from %s', $provider),
            previous: $previous
        );
    }
}
