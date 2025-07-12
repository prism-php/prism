<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Concerns;

use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\ProviderRateLimit;

trait HandleResponseError
{
    protected Response $httpResponse;

    protected function handleResponseError(): void
    {
        $data = $this->httpResponse->json();

        if (! $data || data_get($data, 'object') === 'error') {
            $message = data_get($data, 'message', 'unknown');

            throw PrismException::providerResponseError(vsprintf(
                'Mistral Error: [%s] %s',
                [
                    data_get($data, 'type', 'unknown'),
                    is_array($message) ? json_encode($message) : $message,
                ]
            ));
        }
    }

    /**
     * @return ProviderRateLimit[]
     */
    abstract protected function processRateLimits(Response $response): array;
}
