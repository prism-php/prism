<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;

trait ValidatesResponse
{
    protected function validateResponse(Response $response): void
    {
        if ($response->getStatusCode() === 429) {
            throw PrismRateLimitedException::make(
                rateLimits: $this->processRateLimits($response),
                retryAfter: $response->header('retry-after') === '' ? null : (int) $response->header('retry-after'),
            );
        }

        $data = $response->json();

        if (! $data || data_get($data, 'error')) {
            throw PrismException::providerResponseError(vsprintf(
                'OpenAI Error:  [%s] %s',
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
