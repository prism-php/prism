<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Groq\Concerns;

use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\ProviderRateLimit;

trait ValidateResponse
{
    protected function validateResponse(Response $response): void
    {
        $data = $response->json();

        if (! $data || data_get($data, 'error')) {
            throw PrismException::providerResponseError(vsprintf(
                'Groq Error:  [%s] %s',
                [
                    data_get($data, 'error.type', 'unknown'),
                    data_get($data, 'error.message', 'unknown'),
                ]
            ));
        }
    }

    /**
     * @return ProviderRateLimit[]
     */
    abstract protected function processRateLimits(Response $response): array;
}
