<?php

declare(strict_types=1);

namespace Tests\Providers\Mistral;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Prism\Prism\Providers\Mistral\Concerns\ProcessRateLimits;
use Prism\Prism\ValueObjects\ProviderRateLimit;

/**
 * @param  array<string, string>  $headers
 * @return ProviderRateLimit[]
 */
function readMistralRateLimits(array $headers): array
{
    $reader = new class
    {
        use ProcessRateLimits {
            processRateLimits as public read;
        }
    };

    return $reader->read(new Response(new PsrResponse(200, $headers)));
}

it('does not invent a quota bucket without both reporting headers', function (array $headers): void {
    expect(readMistralRateLimits($headers))->toBe([]);
})->with([
    'no headers' => [[]],
    'unrelated header' => [['Content-Type' => 'application/json']],
    'reset only' => [['ratelimitbysize-reset' => '30']],
    'limit only' => [['ratelimitbysize-limit' => '1000']],
    'remaining only' => [['ratelimitbysize-remaining' => '500']],
    'missing remaining' => [['ratelimitbysize-limit' => '1000', 'ratelimitbysize-reset' => '30']],
    'missing limit' => [['ratelimitbysize-remaining' => '500', 'ratelimitbysize-reset' => '30']],
]);

it('preserves the reported quota including explicit exhaustion', function (array $headers, int $limit, int $remaining): void {
    $previous = Carbon::getTestNow();

    try {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');

        expect(array_map(fn (ProviderRateLimit $bucket): array => $bucket->toArray(), readMistralRateLimits($headers)))
            ->toBe([[
                'name' => 'tokens',
                'limit' => $limit,
                'remaining' => $remaining,
                'resets_at' => '2026-01-01T00:00:30+00:00',
            ]]);
    } finally {
        Carbon::setTestNow($previous);
    }
})->with([
    'complete headers' => [[
        'ratelimitbysize-limit' => '1000',
        'ratelimitbysize-remaining' => '500',
        'ratelimitbysize-reset' => '30',
    ], 1000, 500],
    'case-insensitive headers' => [[
        'RateLimitBySize-Limit' => '1000',
        'RATELIMITBYSIZE-REMAINING' => '500',
        'RateLimitBySize-Reset' => '30',
    ], 1000, 500],
    'explicit exhausted quota' => [[
        'ratelimitbysize-limit' => '0',
        'ratelimitbysize-remaining' => '0',
        'ratelimitbysize-reset' => '30',
    ], 0, 0],
]);
