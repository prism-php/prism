<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
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
