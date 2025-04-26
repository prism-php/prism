<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\PrismManager;
use Prism\Prism\Text\PendingRequest;
use Tests\TestDoubles\TestProvider;

beforeEach(function (): void {
    $this->manager = resolve(PrismManager::class);

    // Register test provider implementations
    $this->manager->extend('openai', fn (): \Tests\TestDoubles\TestProvider => new TestProvider);
    $this->manager->extend('anthropic', fn (): \Tests\TestDoubles\TestProvider => new TestProvider);
});

test('it applies provider specific options when provider matches', function (): void {
    // Create pending request with OpenAI
    $request = new PendingRequest;
    $request->using(Provider::OpenAI, 'gpt-4');

    // Apply provider-specific configuration for OpenAI
    $request->whenProvider(
        Provider::OpenAI,
        fn (PendingRequest $req): PendingRequest => $req->withMaxTokens(100)
    );

    // Generate the request
    $textRequest = $request->toRequest();

    expect($textRequest->maxTokens())->toBe(100);
});

test('it skips provider specific options when provider doesnt match', function (): void {
    // Create pending request with OpenAI
    $request = new PendingRequest;
    $request->using(Provider::OpenAI, 'gpt-4');

    // Set default max tokens
    $request->withMaxTokens(50);

    // Apply provider-specific configuration for Anthropic (should be skipped)
    $request->whenProvider(
        Provider::Anthropic,
        fn (PendingRequest $req): PendingRequest => $req->withMaxTokens(100)
    );

    // Generate the request
    $textRequest = $request->toRequest();

    expect($textRequest->maxTokens())->toBe(50);
});

test('it can chain multiple provider conditions', function (): void {
    // Create pending request with Anthropic
    $request = new PendingRequest;
    $request->using(Provider::Anthropic, 'claude-3');

    // Chain multiple provider conditions
    $request
        ->whenProvider(
            Provider::OpenAI,
            fn (PendingRequest $req): PendingRequest => $req->withMaxTokens(100)
        )
        ->whenProvider(
            Provider::Anthropic,
            fn (PendingRequest $req): PendingRequest => $req->withMaxTokens(200)
        );

    // Generate the request
    $textRequest = $request->toRequest();

    expect($textRequest->maxTokens())->toBe(200);
});

test('it works with string provider names', function (): void {
    // Create pending request with OpenAI (using string rather than enum)
    $request = new PendingRequest;
    $request->using('openai', 'gpt-4');

    // Apply provider-specific configuration with string name
    $request->whenProvider(
        'openai',
        fn (PendingRequest $req): PendingRequest => $req->withMaxTokens(100)
    );

    // Generate the request
    $textRequest = $request->toRequest();

    expect($textRequest->maxTokens())->toBe(100);
});

test('it allows setting provider options', function (): void {
    // Create pending request with Anthropic
    $request = new PendingRequest;
    $request->using(Provider::Anthropic, 'claude-3');

    // Apply provider-specific options
    $request->whenProvider(
        Provider::Anthropic,
        fn (PendingRequest $req): PendingRequest => $req
            ->withProviderOptions(['cacheType' => 'ephemeral'])
    );

    // Generate the request
    $textRequest = $request->toRequest();

    expect($textRequest->providerOptions())->toBe(['cacheType' => 'ephemeral']);
});

test('it accepts invokable class', function (): void {
    // Create pending request with OpenAI
    $request = new PendingRequest;
    $request->using(Provider::OpenAI, 'gpt-4');

    // Create an invokable class for provider-specific configuration
    $invokable = new class
    {
        public function __invoke(PendingRequest $req): PendingRequest
        {
            return $req->withMaxTokens(300);
        }
    };

    // Apply provider-specific configuration using invokable class
    $request->whenProvider(Provider::OpenAI, $invokable);

    // Generate the request
    $textRequest = $request->toRequest();

    expect($textRequest->maxTokens())->toBe(300);
});
