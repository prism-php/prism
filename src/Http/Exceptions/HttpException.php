<?php

declare(strict_types=1);

namespace Prism\Prism\Http\Exceptions;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Http\Response;
use Throwable;

class HttpException extends PrismException
{
    public function __construct(
        public readonly Response $response,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        if ($message === '' || $message === '0') {
            $message = $this->prepareMessage($response);
        }

        parent::__construct($message, $code, $previous);
    }

    protected function prepareMessage(Response $response): string
    {
        $message = "HTTP request failed [{$response->status()}]";

        if ($response->reason() !== '' && $response->reason() !== '0') {
            $message .= " {$response->reason()}";
        }

        return $message;
    }
}
