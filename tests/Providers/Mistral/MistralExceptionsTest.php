<?php

declare(strict_types=1);

namespace Tests\Providers\Mistral;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Prism;
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
        ->generate();

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
                ->generate();
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
