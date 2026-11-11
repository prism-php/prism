<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Prism\Prism\ValueObjects\ProviderRateLimit;

trait ProcessRateLimits
{
    /**
     * @return ProviderRateLimit[]
     */
    protected function processRateLimits(Response $response): array
    {
        $headers = $response->getHeaders();
        $rateLimits = [];

        // Process requests rate limit
        if (isset($headers['x-ratelimit-limit-requests']) && isset($headers['x-ratelimit-remaining-requests'])) {
            $rateLimits[] = new ProviderRateLimit(
                name: 'requests',
                limit: (int) $headers['x-ratelimit-limit-requests'][0],
                remaining: (int) $headers['x-ratelimit-remaining-requests'][0],
                resetsAt: $this->parseResetTime($headers['x-ratelimit-reset-requests'][0] ?? null)
            );
        }

        // Process tokens rate limit
        if (isset($headers['x-ratelimit-limit-tokens']) && isset($headers['x-ratelimit-remaining-tokens'])) {
            $rateLimits[] = new ProviderRateLimit(
                name: 'tokens',
                limit: (int) $headers['x-ratelimit-limit-tokens'][0],
                remaining: (int) $headers['x-ratelimit-remaining-tokens'][0],
                resetsAt: $this->parseResetTime($headers['x-ratelimit-reset-tokens'][0] ?? null)
            );
        }

        return $rateLimits;
    }

    protected function parseResetTime(?string $resetTime): ?Carbon
    {
        if (in_array($resetTime, [null, '', '0'], true)) {
            return null;
        }

        // A Go duration, e.g. "20ms", "1s", "6m0s" or "1h2m3.5s"
        if (preg_match('/^(?:\d+(?:\.\d+)?(?:ms|h|m|s))+$/', $resetTime) !== 1) {
            return null;
        }

        preg_match_all('/(\d+(?:\.\d+)?)(ms|h|m|s)/', $resetTime, $parts, PREG_SET_ORDER);

        $milliseconds = 0.0;

        foreach ($parts as [, $value, $unit]) {
            $milliseconds += (float) $value * match ($unit) {
                'ms' => 1,
                's' => 1_000,
                'm' => 60_000,
                'h' => 3_600_000,
            };
        }

        return Carbon::now()->addMilliseconds((int) round($milliseconds));
    }
}
