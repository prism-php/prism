<?php

declare(strict_types=1);

namespace Tests\Providers\Mistral;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Mistral\Concerns\ValidatesResponse;
use Prism\Prism\ValueObjects\ProviderRateLimit;

arch()->expect([
    'Providers\Mistral\Handlers\Text',
    'Providers\Mistral\Handlers\Embeddings',
])
    ->toUseTrait(ValidatesResponse::class);

it('throws a PrismRateLimitedException with a 429 response code', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    Prism::text()
        ->using(Provider::Mistral, 'fake-model')
        ->withPrompt('Hello world!')
        ->asText();

})->throws(PrismRateLimitedException::class);

it('sets the correct data on the PrismRateLimitedException', function (): void {
    $this->freezeTime(function (Carbon $time): void {
        $time = $time->toImmutable();
        Http::fake([
            '*' => Http::response(
                status: 429,
                headers: [
                    'ratelimitbysize-limit' => 500000,
                    'ratelimitbysize-remaining' => 499900,
                    'ratelimitbysize-reset' => 28,
                ]
            ),
        ])->preventStrayRequests();

        try {
            Prism::text()
                ->using(Provider::Mistral, 'fake-model')
                ->withPrompt('Hello world!')
                ->asText();
        } catch (PrismRateLimitedException $e) {
            expect($e->retryAfter)->toEqual(null);
            expect($e->rateLimits)->toHaveCount(1);
            expect($e->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
            expect($e->rateLimits[0]->name)->toEqual('tokens');
            expect($e->rateLimits[0]->limit)->toEqual(500000);
            expect($e->rateLimits[0]->remaining)->toEqual(499900);
            expect($e->rateLimits[0]->resetsAt->equalTo($time->addSeconds(28)))->toBeTrue();
        }
    });
});

it('sets the rate limits Mistral sends now on the PrismRateLimitedException', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
            headers: [
                'x-ratelimit-limit-tokens-minute' => 400000,
                'x-ratelimit-remaining-tokens-minute' => 0,
                'x-ratelimit-limit-req-minute' => 15,
                'x-ratelimit-remaining-req-minute' => 9,
            ]
        ),
    ])->preventStrayRequests();

    try {
        Prism::text()
            ->using(Provider::Mistral, 'fake-model')
            ->withPrompt('Hello world!')
            ->asText();

        $this->fail('PrismRateLimitedException was not thrown');
    } catch (PrismRateLimitedException $e) {
        expect($e->rateLimits)->toHaveCount(2);
        expect($e->rateLimits[0]->name)->toBe('tokens');
        expect($e->rateLimits[0]->limit)->toBe(400000);
        expect($e->rateLimits[0]->remaining)->toBe(0);
        expect($e->rateLimits[1]->name)->toBe('requests');
        expect($e->rateLimits[1]->remaining)->toBe(9);
    }
});
