<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\ValueObjects\ProviderRateLimit;

trait ValidatesResponse
{
    protected function validateResponse(Response $response): void
    {
        if ($response->getStatusCode() === 429) {
            throw PrismRateLimitedException::make(
                rateLimits: $this->processRateLimits($response),
                retryAfter: null
            );
        }

        $data = $response->json();

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
    protected function processRateLimits(Response $response): array
    {
        return [
            new ProviderRateLimit(
                name: 'tokens',
                limit: (int) $response->header('ratelimitbysize-limit'),
                remaining: (int) $response->header('ratelimitbysize-remaining'),
                resetsAt: Carbon::now()->addSeconds((int) $response->header('ratelimitbysize-reset')),
            ),
        ];
    }
}
