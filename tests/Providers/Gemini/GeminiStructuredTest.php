<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Messages\Support\Document;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
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
        ->asStructured();

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

it('can use a cache object with a structured request', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'gemini/use-cache-with-structured');

    /** @var Gemini */
    $provider = Prism::provider(Provider::Gemini);

    $object = $provider->cache(
        model: 'gemini-1.5-flash-002',
        messages: [
            new UserMessage('', [
                Document::fromPath('tests/Fixtures/long-document.pdf'),
            ]),
        ],
        systemPrompts: [
            new SystemMessage('You are a legal analyst.'),
        ],
        ttl: 30
    );

    $response = Prism::structured()
        ->using(Provider::Gemini, 'gemini-1.5-flash-002')
        ->withSchema(new ObjectSchema('answer', '', [
            new StringSchema('legal_jurisdiction', 'Which legal jurisdiction is this document from?'),
            new StringSchema('legislation_type', 'What type of legislation is this (e.g. a treaty, a regulation, an act, a directive, etc.)?'),
            new NumberSchema('article_count', 'How many articles does the main body of the legislation contain?'),
        ]))
        ->withProviderMeta(Provider::Gemini, ['cachedContentName' => $object->name])
        ->withPrompt('Summarise this document using the properties and descriptions defined in the schema.')
        ->generate();

    Http::assertSentInOrder([
        fn (Request $request): bool => true,
        fn (Request $request): bool => $request->data()['cachedContent'] === $object->name,
    ]);

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'legal_jurisdiction',
        'legislation_type',
        'article_count',
    ]);

    expect($response->structured['article_count'])->toBe(358);
    expect($response->structured['legal_jurisdiction'])->toBe('European Union');
    expect($response->structured['legislation_type'])->toBe('Treaty');
});
