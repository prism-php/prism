<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Prism;
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
