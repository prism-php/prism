<?php

declare(strict_types=1);

namespace Prism\Prism\Http\Concerns;

use Prism\Prism\Http\Exceptions\RequestException;
use Prism\Prism\Http\Response;

trait HandlesErrors
{
    protected function handleErrorResponse(Response $response): Response
    {
        if ($response->failed()) {
            throw new RequestException($response);
        }

        return $response;
    }

    protected function shouldRetryOnError(Response $response): bool
    {
        if ($response->serverError()) {
            return true;
        }
        return $response->tooManyRequests();
    }
}
