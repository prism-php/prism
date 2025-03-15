<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Prism;
use Prism\Prism\Providers\Gemini\Concerns\ValidatesResponse;

arch()->expect([
    'Providers\Gemini\Handlers\Text',
    'Providers\Gemini\Handlers\Structured',
])
    ->toUseTrait(ValidatesResponse::class);

it('throws a PrismRateLimitedException with a 429 response code for text and structured', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    Prism::text()
        ->using(Provider::Gemini, 'fake-model')
        ->withPrompt('Hello world!')
        ->asText();

})->throws(PrismRateLimitedException::class);

it('throws a PrismRateLimitedException with a 429 response code for emebddings', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    Prism::embeddings()
        ->using(Provider::Gemini, 'fake-model')
        ->fromInput('Hello world!')
        ->asEmbeddings();

})->throws(PrismRateLimitedException::class);
