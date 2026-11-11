<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Groq\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
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
        $limitHeaders = array_filter(
            $response->getHeaders(),
            fn ($headerName) => Str::startsWith($headerName, 'x-ratelimit-'),
            ARRAY_FILTER_USE_KEY
        );

        $rateLimits = [];

        foreach ($limitHeaders as $headerName => $headerValues) {
            $limitName = Str::of($headerName)->afterLast('-')->toString();
            $fieldName = Str::of($headerName)->after('x-ratelimit-')->beforeLast('-')->toString();

            $rateLimits[$limitName][$fieldName] = $headerValues[0];
        }

        return array_values(Arr::map($rateLimits, fn ($fields, $limitName): ProviderRateLimit => new ProviderRateLimit(
            name: $limitName,
            limit: data_get($fields, 'limit') !== null
                ? (int) data_get($fields, 'limit')
                : null,
            remaining: data_get($fields, 'remaining') !== null
                ? (int) data_get($fields, 'remaining')
                : null,
            resetsAt: $this->resetsAtFromDuration(data_get($fields, 'reset'))
        )));
    }
}
