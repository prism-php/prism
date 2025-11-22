<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Gemini\Concerns\ValidatesResponse;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

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

it('throws a PrismRateLimitedException with a 429 response code for embeddings', function (): void {
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

it('throws a PrismRateLimitedException with a 429 response code for cache', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $provider->cache(
        model: 'gemini-1.5-flash-002',
        messages: [
            new UserMessage('', [
                Document::fromLocalPath('tests/Fixtures/long-document.pdf'),
            ]),
        ],
        systemPrompts: [
            new SystemMessage('You are a legal analyst.'),
        ],
        ttl: 60
    );

})->throws(PrismRateLimitedException::class);
