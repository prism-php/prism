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

    /**
     * A registered prism that returns tool calls to its caller cannot stream: the stream carries text
     * only, so a returned call would either be dropped or have to be run. Refused before any provider call.
     */
    public static function streamingReturnedTools(string $model): self
    {
        $exception = new self(sprintf('Prism "%s" returns tool calls to the caller and cannot be streamed', $model));
        $exception->status = 400;
        $exception->type = 'invalid_request_error';
        $exception->errorCode = 'stream_not_supported';
        $exception->publicMessage = 'This model does not support streaming. Send the request with "stream": false.';

        return $exception;
    }

    /**
     * An assistant message in the history carries tool calls. The public message names the position and
     * the reason because both are the caller's own input: the index is an integer and the text is fixed.
     */
    public static function replayedToolCalls(int $index): self
    {
        $exception = self::invalidMessages(sprintf('Assistant message %d carries tool calls', $index));
        $exception->publicMessage = sprintf(
            'messages[%d]: an assistant message carrying tool calls cannot be replayed; a returned tool call ends the conversation.',
            $index,
        );

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
