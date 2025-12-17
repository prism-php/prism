<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.opencodezen.api_key', env('OPENCODEZEN_API_KEY'));
});

it('can generate text with a prompt', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'opencodezen/generate-text-with-a-prompt');

    $response = Prism::text()
        ->using(Provider::OpenCodeZen, 'gpt-4')
        ->withPrompt('Who are you?')
        ->generate();

    // Assert response type
    expect($response)->toBeInstanceOf(TextResponse::class);

    // Assert usage
    expect($response->usage->promptTokens)->toBe(7);
    expect($response->usage->completionTokens)->toBe(35);

    // Assert metadata
    expect($response->meta->id)->toBe('gen-12345');
    expect($response->meta->model)->toBe('gpt-4');

    expect($response->text)->toBe(
        "Hello! I'm an AI assistant powered by OpenCodeZen. I can help you with various tasks, answer questions, and assist with information on a wide range of topics. How can I help you today?"
    );

    // Assert finish reason
    expect($response->finishReason)->toBe(FinishReason::Stop);
});
