<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

beforeEach(function (): void {
    config()->set('prism.providers.perplexity.api_key', 'pplx-test');

    Http::fake([
        '*' => Http::response([
            'id' => 'pplx-header',
            'model' => 'sonar',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]),
    ])->preventStrayRequests();
});

it('sends the integration header to the Perplexity API', function (): void {
    Prism::text()
        ->using(Provider::Perplexity, 'sonar')
        ->withPrompt('Hi')
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->toPsrRequest()->getHeader('X-Pplx-Integration') === ['prism']);
});

it('keeps a caller supplied integration header regardless of case', function (string $name): void {
    Prism::text()
        ->using(Provider::Perplexity, 'sonar')
        ->withClientOptions(['headers' => [$name => 'my-app']])
        ->withPrompt('Hi')
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->toPsrRequest()->getHeader('X-Pplx-Integration') === ['my-app']
        && count(array_filter(
            array_keys($request->headers()),
            fn (string $key): bool => strcasecmp($key, 'X-Pplx-Integration') === 0
        )) === 1);
})->with(['X-Pplx-Integration', 'x-pplx-integration']);

it('does not send the integration header to a custom host', function (): void {
    config()->set('prism.providers.perplexity.url', 'https://proxy.example.com');

    Prism::text()
        ->using(Provider::Perplexity, 'sonar')
        ->withPrompt('Hi')
        ->asText();

    Http::assertSent(fn (Request $request): bool => ! $request->toPsrRequest()->hasHeader('X-Pplx-Integration'));
});
