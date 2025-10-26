<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;

it('can generate text and assert provider', function (): void {
    $fakeResponse = TextResponseFake::make()
        ->withText('Hello, I am Claude!')
        ->withUsage(new Usage(10, 20));

    // Set up the fake
    $fake = Prism::fake([$fakeResponse]);

    // Run your code
    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withPrompt('Who are you?')
        ->asText();

    // Make assertions
    expect($response->text)->toBe('Hello, I am Claude!');

    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->provider())->toBe('anthropic');
        expect($requests[0]->model())->toBe('claude-3-5-sonnet-latest');
    });
});

it('can generate text and assert provider with different providers', function (): void {
    $fakeResponse = TextResponseFake::make()
        ->withText('Hello from OpenAI!')
        ->withUsage(new Usage(15, 25));

    // Set up the fake
    $fake = Prism::fake([$fakeResponse]);

    // Run your code
    $response = Prism::text()
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt('Hello')
        ->asText();

    // Make assertions
    expect($response->text)->toBe('Hello from OpenAI!');

    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->provider())->toBe('openai');
        expect($requests[0]->model())->toBe('gpt-4');
    });
});
