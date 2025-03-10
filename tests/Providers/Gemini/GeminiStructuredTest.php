<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

it('returns structured output', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/generate-structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast', true),
            new BooleanSchema('coat_required', 'whether a coat is required', true),
            new EnumSchema('game_time', 'The time of the game', ['1:00 PM', '7:00 PM'], true),
            new NumberSchema('temperature', 'The temperature in Fahrenheit', true),
            new ObjectSchema(
                'location',
                'The location of the game',
                [
                    new StringSchema('city', 'The city', true),
                    new StringSchema('state', 'The state', true),
                ],
                ['city', 'state'],
                false,
                true
            ),
            new ArraySchema(
                'players',
                'The players in the game',
                new StringSchema('player', 'The player', true),
                true
            ),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $response = Prism::structured()
        ->using(Provider::Gemini, 'gemini-1.5-flash-002')
        ->withSchema($schema)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->generate();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'weather',
        'game_time',
        'coat_required',
        'temperature',
        'location',
        'players',
    ]);
    expect($response->structured['weather'])->toBeString();
    expect($response->structured['game_time'])->toBeString();
    expect($response->structured['coat_required'])->toBeBool();
    expect($response->structured['temperature'])->toBeInt();
    expect($response->structured['location'])->toBeArray();
    expect($response->structured['location'])->toHaveKeys(['city', 'state']);
    expect($response->structured['location']['city'])->toBeString();
    expect($response->structured['location']['state'])->toBeString();
    expect($response->structured['players'])->toBeArray();
    expect($response->structured['players'][0])->toBeString();

    expect($response->usage->promptTokens)->toBe(81);
    expect($response->usage->completionTokens)->toBe(64);
});
