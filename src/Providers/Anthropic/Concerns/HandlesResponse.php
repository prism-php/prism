<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\ValueObjects\ProviderRateLimit;

trait HandlesResponse
{
    protected function handleResponseExceptions(Response $response): void
    {
        if ($response->getStatusCode() === 429) {
            throw PrismRateLimitedException::make(
                rateLimits: $this->processRateLimits($response),
                retryAfter: $response->hasHeader('retry-after')
                    ? (int) $response->getHeader('retry-after')[0]
                    : null
            );
        }

        if ($response->getStatusCode() === 529) {
            throw PrismProviderOverloadedException::make(Provider::Anthropic);
        }

        if ($response->getStatusCode() === 413) {
            throw PrismRequestTooLargeException::make(Provider::Anthropic);
        }
    }

    /**
     * @return array<int, ProviderRateLimit>
     */
    protected function processRateLimits(Response $response): array
    {
        $rate_limits = [];

        foreach ($response->getHeaders() as $headerName => $headerValues) {
            if (Str::startsWith($headerName, 'anthropic-ratelimit-') === false) {
                continue;
            }

            $limit_name = Str::of($headerName)->after('anthropic-ratelimit-')->beforeLast('-')->toString();
            $field_name = Str::of($headerName)->afterLast('-')->toString();
            $rate_limits[$limit_name][$field_name] = $headerValues[0];
        }

        return array_values(Arr::map($rate_limits, function ($fields, $limit_name): ProviderRateLimit {
            $resets_at = data_get($fields, 'reset');

            return new ProviderRateLimit(
                name: $limit_name,
                limit: data_get($fields, 'limit') !== null
                    ? (int) data_get($fields, 'limit')
                    : null,
                remaining: data_get($fields, 'remaining') !== null
                    ? (int) data_get($fields, 'remaining')
                    : null,
                resetsAt: data_get($fields, 'reset') !== null ? new Carbon($resets_at) : null
            );
        }));
    }
}
