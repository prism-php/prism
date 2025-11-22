<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

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
