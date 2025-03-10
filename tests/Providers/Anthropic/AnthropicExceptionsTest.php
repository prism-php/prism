<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\ProviderRateLimit;

it('throws a RateLimitException if the Anthropic responds with a 429', function (): void {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withPrompt('Hello world!')
        ->generate();

})->throws(PrismRateLimitedException::class);

it('sets the correct data on the RateLimitException', function (): void {
    $requests_reset = Carbon::now()->addSeconds(30);

    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            status: 429,
            headers: [
                'anthropic-ratelimit-requests-limit' => 1000,
                'anthropic-ratelimit-requests-remaining' => 500,
                'anthropic-ratelimit-requests-reset' => $requests_reset->toISOString(),
                'anthropic-ratelimit-input-tokens-limit' => 80000,
                'anthropic-ratelimit-input-tokens-remaining' => 0,
                'anthropic-ratelimit-input-tokens-reset' => Carbon::now()->addSeconds(60)->toISOString(),
                'anthropic-ratelimit-output-tokens-limit' => 16000,
                'anthropic-ratelimit-output-tokens-remaining' => 15000,
                'anthropic-ratelimit-output-tokens-reset' => Carbon::now()->addSeconds(5)->toISOString(),
                'anthropic-ratelimit-tokens-limit' => 96000,
                'anthropic-ratelimit-tokens-remaining' => 15000,
                'anthropic-ratelimit-tokens-reset' => Carbon::now()->addSeconds(5)->toISOString(),
                'retry-after' => 40,
            ]
        ),
    ])->preventStrayRequests();

    try {
        Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Hello world!')
            ->generate();
    } catch (PrismRateLimitedException $e) {
        expect($e->retryAfter)->toEqual(40);
        expect($e->rateLimits)->toHaveCount(4);
        expect($e->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
        expect($e->rateLimits[0]->name)->toEqual('requests');
        expect($e->rateLimits[0]->limit)->toEqual(1000);
        expect($e->rateLimits[0]->remaining)->toEqual(500);
        expect($e->rateLimits[0]->resetsAt)->toEqual($requests_reset);

        expect($e->rateLimits[1]->name)->toEqual('input-tokens');
        expect($e->rateLimits[1]->limit)->toEqual(80000);
        expect($e->rateLimits[1]->remaining)->toEqual(0);
    }
});

it('throws an overloaded exception if the Anthropic responds with a 529', function (): void {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            status: 529,
        ),
    ])->preventStrayRequests();

    Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withPrompt('Hello world!')
        ->generate();

})->throws(PrismProviderOverloadedException::class);

it('throws a request too large exception if the Anthropic responds with a 413', function (): void {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(
            status: 413,
        ),
    ])->preventStrayRequests();

    Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withPrompt('Hello world!')
        ->generate();

})->throws(PrismRequestTooLargeException::class);
