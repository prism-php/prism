<?php

declare(strict_types=1);

namespace Prism\Prism\Http\Exceptions;

use Prism\Prism\Exceptions\PrismException;
use Throwable;

class ConnectionException extends PrismException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message ?: 'Connection failed', $code, $previous);
    }
}
