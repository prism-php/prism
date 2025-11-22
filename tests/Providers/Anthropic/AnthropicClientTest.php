<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

it('includes anthropic beta header if set in config', function (): void {
    config()->set('prism.providers.anthropic.anthropic_beta', 'beta1,beta2');

    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using('anthropic', 'claude-3-7-sonnet-latest')
        ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
        ->withProviderOptions(['thinking' => ['enabled' => true]])
        ->asText();

    Http::assertSent(fn (Request $request) => $request->hasHeader('anthropic-beta', 'beta1,beta2'));
});

it('uses the configured url', function (): void {
    config()->set('prism.providers.anthropic.url', 'https://example.com');

    FixtureResponse::fakeResponseSequence('messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withPrompt('Hello')
        ->asText();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://example.com'));
});
