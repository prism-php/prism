<?php

declare(strict_types=1);

namespace Prism\Prism\Exceptions;

use Exception;
use Throwable;

class PrismServerException extends Exception
{
    public static function unresolvableModel(string $model, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Prism "%s" is not registered with PrismServer', $model),
            previous: $previous
        );
    }
}
