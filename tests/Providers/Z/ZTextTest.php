<?php

declare(strict_types=1);

namespace Tests\Providers\Z;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.z.api_key', env('Z_API_KEY', 'zai-123'));
});

it('Z provider handles text request', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'z/generate-text-with-a-prompt');

    $response = Prism::text()
        ->using(Provider::Z, 'z-model')
        ->withPrompt('Hello!')
        ->asText();

    expect($response->text)->toBe('Hello! How can I help you today?')
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->usage->promptTokens)->toBe(9)
        ->and($response->usage->completionTokens)->toBe(12)
        ->and($response->meta->id)->toBe('chatcmpl-123')
        ->and($response->meta->model)->toBe('z-model');
});
