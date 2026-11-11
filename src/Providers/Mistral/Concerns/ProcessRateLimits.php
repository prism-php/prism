<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Concerns;

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
        $headers = array_change_key_case(array_map(
            fn (array $values): string => $values[0] ?? '',
            $response->getHeaders()
        ));

        // Mistral used to send the token limit as ratelimitbysize-*, with a reset in seconds
        if (isset($headers['ratelimitbysize-limit'])) {
            return [
                new ProviderRateLimit(
                    name: 'tokens',
                    limit: (int) $headers['ratelimitbysize-limit'],
                    remaining: (int) ($headers['ratelimitbysize-remaining'] ?? 0),
                    resetsAt: Carbon::now()->addSeconds((int) ($headers['ratelimitbysize-reset'] ?? 0)),
                ),
            ];
        }

        $rateLimits = [];

        // e.g. x-ratelimit-limit-tokens-minute and x-ratelimit-remaining-tokens-minute, without a reset time
        foreach (['tokens' => 'tokens-minute', 'requests' => 'req-minute'] as $name => $window) {
            $limit = $headers["x-ratelimit-limit-{$window}"] ?? null;
            $remaining = $headers["x-ratelimit-remaining-{$window}"] ?? null;

            if ($limit === null && $remaining === null) {
                continue;
            }

            $rateLimits[] = new ProviderRateLimit(
                name: $name,
                limit: $limit !== null ? (int) $limit : null,
                remaining: $remaining !== null ? (int) $remaining : null,
            );
        }

        return $rateLimits;
    }
}
