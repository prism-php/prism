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
