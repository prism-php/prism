<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Prism\Prism\ValueObjects\ProviderRateLimit;

trait ProcessesRateLimits
{
    /**
     * Process rate limit headers from Anthropic API responses.
     *
     * @return ProviderRateLimit[]
     */
    protected function processRateLimits(Response $response): array
    {
        $limitHeaders = array_filter($response->getHeaders(), fn ($headerName) => Str::startsWith($headerName, 'x-ratelimit-'), ARRAY_FILTER_USE_KEY);

        $rateLimits = [];

        foreach ($limitHeaders as $headerName => $headerValues) {
            // Parse header name according to Anthropic's format: x-ratelimit-{limit/remaining/reset}-{resource_type}
            $parts = explode('-', $headerName);

            // Expect format like x-ratelimit-limit-requests or x-ratelimit-remaining-tokens
            if (count($parts) >= 3) {
                $fieldName = $parts[1]; // limit, remaining, or reset
                $limitName = $parts[2]; // requests, tokens, etc.

                $rateLimits[$limitName][$fieldName] = $headerValues[0];
            }
        }

        // Also check for retry-after header
        $retryAfter = $response->header('retry-after');
        if ($retryAfter !== null) {
            $rateLimits['global']['reset'] = $retryAfter . 's';
        }

        return array_values(Arr::map($rateLimits, function ($fields, $limitName): ProviderRateLimit {
            $resetsAt = data_get($fields, 'reset', '');

            // Anthropic typically provides reset times in seconds
            if (is_numeric($resetsAt)) {
                $resetSeconds = (int) $resetsAt;
                $resetMilliseconds = 0;
                $resetMinutes = 0;
            } elseif (str_contains($resetsAt, 's')) {
                $resetSeconds = (int) Str::of($resetsAt)->before('s')->toString();
                $resetMilliseconds = 0;
                $resetMinutes = 0;
            } else {
                $resetSeconds = 0;
                $resetMilliseconds = 0;
                $resetMinutes = 0;
            }

            return new ProviderRateLimit(
                name: $limitName,
                limit: data_get($fields, 'limit') !== null
                    ? (int) data_get($fields, 'limit')
                    : null,
                remaining: data_get($fields, 'remaining') !== null
                    ? (int) data_get($fields, 'remaining')
                    : null,
                resetsAt: data_get($fields, 'reset') !== null
                    ? Carbon::now()->addMinutes((int) $resetMinutes)->addSeconds((int) $resetSeconds)->addMilliseconds((int) $resetMilliseconds)
                    : null
            );
        }));
    }
}