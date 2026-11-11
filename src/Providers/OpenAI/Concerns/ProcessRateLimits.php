<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Prism\Prism\Concerns\ReadsResetDurations;
use Prism\Prism\ValueObjects\ProviderRateLimit;

trait ProcessRateLimits
{
    use ReadsResetDurations;

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
        return $this->resetsAtFromDuration($resetTime);
    }
}
