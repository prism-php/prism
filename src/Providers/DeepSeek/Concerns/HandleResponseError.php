<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\DeepSeek\Concerns;

use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;

trait HandleResponseError
{
    protected Response $httpResponse;

    protected function handleResponseError(): void
    {
        if ($this->httpResponse->json() === []) {
            throw PrismException::providerResponseError('DeepSeek Error: Empty response');
        }
    }
}
