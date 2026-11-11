<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

test('text throws a PrismRateLimitedException with a 429 response code', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    Prism::text()
        ->using(Provider::OpenAI, 'fake-model')
        ->withPrompt('Hello world!')
        ->asText();
})->throws(PrismRateLimitedException::class);

test('structured throws a PrismRateLimitedException with a 429 response code', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    Prism::structured()
        ->using(Provider::OpenAI, 'fake-model')
        ->withSchema(new ObjectSchema('name', 'description', [new StringSchema('name', 'description')]))
        ->withPrompt('Hello world!')
        ->asStructured();
})->throws(PrismRateLimitedException::class);

test('stream throws a PrismRateLimitedException', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-rate-limited');

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $chunk) {
        // Rector, leave me alone!
    }
})->throws(PrismRateLimitedException::class);

test('rate limits read the reset durations OpenAI sends', function (string $requests, string $tokens, int $requestsMs, int $tokensMs): void {
    $this->freezeTime(function (Carbon $time) use ($requests, $tokens, $requestsMs, $tokensMs): void {
        $time = $time->toImmutable();
        Http::fake([
            '*' => Http::response(
                status: 429,
                headers: [
                    'x-ratelimit-limit-requests' => 60,
                    'x-ratelimit-remaining-requests' => 0,
                    'x-ratelimit-reset-requests' => $requests,
                    'x-ratelimit-limit-tokens' => 150000,
                    'x-ratelimit-remaining-tokens' => 149984,
                    'x-ratelimit-reset-tokens' => $tokens,
                ],
            ),
        ])->preventStrayRequests();

        try {
            Prism::text()
                ->using(Provider::OpenAI, 'fake-model')
                ->withPrompt('Hello world!')
                ->asText();

            $this->fail('The request should be rate limited.');
        } catch (PrismRateLimitedException $e) {
            expect($e->rateLimits[0]->resetsAt?->equalTo($time->addMilliseconds($requestsMs)))->toBeTrue()
                ->and($e->rateLimits[1]->resetsAt?->equalTo($time->addMilliseconds($tokensMs)))->toBeTrue();
        }
    });
})->with([
    'the example of the OpenAI docs' => ['1s', '6m0s', 1_000, 360_000],
    'fractions and hours' => ['8.64s', '1h2m3.5s', 8_640, 3_723_500],
    'milliseconds' => ['20ms', '2m59.56s', 20, 179_560],
]);
