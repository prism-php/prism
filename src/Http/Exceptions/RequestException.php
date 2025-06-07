<?php

declare(strict_types=1);

namespace Prism\Prism\Http\Exceptions;

use Prism\Prism\Http\Response;
use Throwable;

class RequestException extends HttpException
{
    public function __construct(
        Response $response,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($response, $message, $code, $previous);
    }

    protected function prepareMessage(Response $response): string
    {
        $message = "HTTP request failed with status {$response->status()}";

        if ($response->reason() !== '' && $response->reason() !== '0') {
            $message .= " ({$response->reason()})";
        }

        if ($response->body() !== '' && $response->body() !== '0') {
            $body = $response->body();
            if (strlen($body) > 100) {
                $body = substr($body, 0, 100).'...';
            }
            $message .= ": {$body}";
        }

        return $message;
    }
}
