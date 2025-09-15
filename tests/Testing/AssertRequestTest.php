<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Tests\Fixtures\FixtureResponse;

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

describe('Success Handlers', function (): void {
    it('calls success handlers on successful response', function (): void {

        $fakeResponse = TextResponseFake::make()
            ->withText('Hello, I am Claude!')
            ->withUsage(new Usage(10, 20));

        // Set up the fake
        $fake = Prism::fake([$fakeResponse]);

        // Run your code
        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->onSuccess(function ($request, $response): void {
                expect($response->usage->promptTokens)->toBe(10);
                expect($response->usage->completionTokens)->toBe(20);
                expect($request->model())->toBe('claude-3-5-sonnet-latest');
                expect($request->provider())->toBe('anthropic');
            })
            ->withPrompt('Who are you?')
            ->asText();

        // Make assertions
        expect($response->text)->toBe('Hello, I am Claude!');

        $fake->assertRequest(function (array $requests): void {
            expect($requests[0]->provider())->toBe('anthropic');
            expect($requests[0]->model())->toBe('claude-3-5-sonnet-latest');
        });
    });

    it('does not call success handlers on unsuccessful response', function (): void {

        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt', [], 404);

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->onSuccess(function ($request, $response): void {
                $this->fail('Reached success function, on failure');
            })
            ->withPrompt('Who are you?')
            ->asText();

    })->throws(\Prism\Prism\Exceptions\PrismException::class);
});
