<?php

declare(strict_types=1);

namespace Prism\Prism\Http\Exceptions;

use Prism\Prism\Http\Response;
use Throwable;

class ProviderHttpException extends HttpException
{
    public function __construct(
        public readonly string $provider,
        Response $response,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($response, $message, $code, $previous);
    }

    protected function prepareMessage(Response $response): string
    {
        $message = "Provider '{$this->provider}' HTTP request failed [{$response->status()}]";

        if ($response->reason() !== '' && $response->reason() !== '0') {
            $message .= " {$response->reason()}";
        }

        return $message;
    }
}
