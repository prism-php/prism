<?php

declare(strict_types=1);

namespace Prism\Prism\Exceptions;

use Throwable;

class PrismStreamDecodeException extends PrismException
{
    public function __construct(string $provider, Throwable $previous)
    {
        parent::__construct(
            sprintf('Could not decode stream from %s', $provider),
            previous: $previous
        );
    }
}
