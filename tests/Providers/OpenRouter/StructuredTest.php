<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Response as StructuredResponse;
use Tests\Fixtures\FixtureResponse;

it('returns structured output', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withSystemPrompt('The tigers game is at 3pm in Detroit, the temperature is expected to be 75º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    // Assert response type
    expect($response)->toBeInstanceOf(StructuredResponse::class);

    // Assert structured data
    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'weather',
        'game_time',
        'coat_required',
    ]);
    expect($response->structured['weather'])->toBeString()->toBe('75º');
    expect($response->structured['game_time'])->toBeString()->toBe('3pm');
    expect($response->structured['coat_required'])->toBeBool()->toBeFalse();

    // Assert metadata
    expect($response->meta->id)->toBe('gen-structured-1');
    expect($response->meta->model)->toBe('openai/gpt-4-turbo');
    expect($response->usage->promptTokens)->toBe(187);
    expect($response->usage->completionTokens)->toBe(26);
});

it('handles missing usage data in response', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/structured-missing-usage');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withSystemPrompt('The tigers game is at 3pm in Detroit, the temperature is expected to be 75º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    expect($response)->toBeInstanceOf(StructuredResponse::class);
    expect($response->structured)->toBeArray();
    expect($response->usage->promptTokens)->toBe(0);
    expect($response->usage->completionTokens)->toBe(0);
});

it('handles responses with missing id and model fields', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/structured-with-missing-meta');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withPrompt('What time is the tigers game today?')
        ->asStructured();

    expect($response)->toBeInstanceOf(StructuredResponse::class);
    expect($response->meta->id)->toBe('');
    expect($response->meta->model)->toBe('openai/gpt-4-turbo');
    expect($response->structured['weather'])->toBe('75º');
});

it('forwards provider options for structured requests', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/structured');

    $schema = new ObjectSchema(
        'summary',
        'Structured summary response',
        [
            new StringSchema('bullet', 'Key takeaway'),
        ],
        ['bullet']
    );

    Prism::structured()
        ->withSchema($schema)
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withPrompt('Summarize the update.')
        ->withProviderOptions([
            'stop' => ['END_STRUCTURED'],
            'seed' => 21,
            'models' => ['openai/gpt-4-turbo'],
            'route' => 'fallback',
            'transforms' => ['markdown'],
            'prediction' => [
                'type' => 'content',
                'content' => '{"bullet": ',
            ],
            'user' => 'structured-user-21',
            'verbosity' => 'medium',
        ])
        ->asStructured();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return $payload['stop'] === ['END_STRUCTURED']
            && $payload['seed'] === 21
            && $payload['models'] === ['openai/gpt-4-turbo']
            && $payload['route'] === 'fallback'
            && $payload['transforms'] === ['markdown']
            && $payload['prediction'] === [
                'type' => 'content',
                'content' => '{"bullet": ',
            ]
            && $payload['user'] === 'structured-user-21'
            && $payload['verbosity'] === 'medium';
    });
});

it('throws enriched exception when content is empty', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/structured-empty-content');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('data', 'Some data'),
        ],
        ['data']
    );

    expect(fn () => Prism::structured()
        ->withSchema($schema)
        ->using(Provider::OpenRouter, 'anthropic/claude-3.5-sonnet')
        ->withPrompt('Give me some data')
        ->asStructured()
    )->toThrow(PrismStructuredDecodingException::class);
});

it('includes raw response context in decoding exception', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/structured-empty-content');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('data', 'Some data'),
        ],
        ['data']
    );

    try {
        Prism::structured()
            ->withSchema($schema)
            ->using(Provider::OpenRouter, 'anthropic/claude-3.5-sonnet')
            ->withPrompt('Give me some data')
            ->asStructured();

        $this->fail('Expected PrismStructuredDecodingException to be thrown');
    } catch (PrismStructuredDecodingException $e) {
        expect($e->getMessage())
            ->toContain('Structured object could not be decoded')
            ->toContain('Model: anthropic/claude-3.5-sonnet')
            ->toContain('Finish reason: stop')
            ->toContain('Raw choices:')
            ->toContain('"content": null');
    }
});
