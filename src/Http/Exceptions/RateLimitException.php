<?php

declare(strict_types=1);

namespace Prism\Prism\Http\Exceptions;

use Prism\Prism\Http\Response;
use Throwable;

class RateLimitException extends ProviderHttpException
{
    public function __construct(
        string $provider,
        Response $response,
        public readonly ?int $retryAfter = null,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($provider, $response, $message, $code, $previous);
    }

    protected function prepareMessage(Response $response): string
    {
        $message = "Provider '{$this->provider}' rate limit exceeded [{$response->status()}]";

        if ($this->retryAfter) {
            $message .= " - retry after {$this->retryAfter} seconds";
        }

        return $message;
    }
}
