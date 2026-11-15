<?php

declare(strict_types=1);

namespace Prism\Prism\Exceptions;

use Exception;
use Throwable;

/**
 * A Prism Server failure that is the caller's to fix, answered with a fixed public message.
 *
 * The exception's own message is for logs. What a caller sees is `publicMessage`, `status`, `type` and
 * `errorCode`, all fixed per case, so nothing internal (provider bodies, upstream URLs, paths, SQL) can
 * reach an API caller through it.
 */
class PrismServerException extends Exception
{
    public int $status = 500;

    public string $type = 'server_error';

    public ?string $errorCode = null;

    public string $publicMessage = 'The server could not complete the request.';

    public static function unresolvableModel(string $model, ?Throwable $previous = null): self
    {
        $exception = new self(
            sprintf('Prism "%s" is not registered with PrismServer', $model),
            previous: $previous
        );
        $exception->status = 404;
        $exception->type = 'invalid_request_error';
        $exception->errorCode = 'model_not_found';
        $exception->publicMessage = 'The requested model does not exist.';

        return $exception;
    }

    public static function invalidMessages(string $reason): self
    {
        $exception = new self($reason);
        $exception->status = 422;
        $exception->type = 'invalid_request_error';
        $exception->errorCode = 'invalid_messages';
        $exception->publicMessage = 'The messages could not be read.';

        return $exception;
    }
}
