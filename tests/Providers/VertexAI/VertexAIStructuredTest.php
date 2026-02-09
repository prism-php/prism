<?php

declare(strict_types=1);

namespace Tests\Providers\VertexAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\AnyOfSchema;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.vertexai.project_id', 'test-project');
    config()->set('prism.providers.vertexai.location', 'us-central1');
    config()->set('prism.providers.vertexai.api_key', 'test-key-1234');
});

it('returns structured output', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/generate-structured');

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
        ->using(Provider::VertexAI, 'gemini-2.5-flash-002')
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

it('sends structured requests to the correct Vertex AI URL', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/generate-structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
        ],
        ['weather']
    );

    Prism::structured()
        ->using(Provider::VertexAI, 'gemini-2.5-flash-002')
        ->withSchema($schema)
        ->withPrompt('What is the weather?')
        ->asStructured();

    Http::assertSent(function (Request $request): bool {
        $url = $request->url();

        expect($url)->toContain('aiplatform.googleapis.com')
            ->and($url)->toContain('projects/test-project')
            ->and($url)->toContain('locations/us-central1')
            ->and($url)->toContain('publishers/google/models')
            ->and($url)->toContain('gemini-2.5-flash-002:generateContent');

        return true;
    });
});

it('works with allowAdditionalProperties set to true', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/generate-structured');

    $schema = new ObjectSchema(
        'book_info',
        'Book information',
        [
            new StringSchema('title', 'Book title'),
            new StringSchema('author', 'Book author'),
        ],
        ['title', 'author'],
        true
    );

    $response = Prism::structured()
        ->using(Provider::VertexAI, 'gemini-2.5-flash-002')
        ->withSchema($schema)
        ->withPrompt('Generate information about a book')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->not->toBeEmpty();
    expect($response->usage->promptTokens)->toBe(81);
    expect($response->usage->completionTokens)->toBe(64);
});

it('supports AnyOfSchema in structured output', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/generate-structured-anyof-simple');

    $schema = new ObjectSchema(
        'response',
        'Response with flexible value',
        [
            new AnyOfSchema(
                schemas: [
                    new StringSchema('text', 'A text value'),
                    new NumberSchema('number', 'A numeric value'),
                ],
                name: 'value',
                description: 'Can be text or number'
            ),
        ],
        ['value']
    );

    $response = Prism::structured()
        ->using(Provider::VertexAI, 'gemini-2.5-flash')
        ->withSchema($schema)
        ->withPrompt('Return the text "forty-two"')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKey('value');
    expect($response->structured['value'])->toBeString();
    expect($response->structured['value'])->toBe('forty-two');

    Http::assertSent(function (Request $request): bool {
        $schema = $request->data()['generationConfig']['response_schema'];

        expect($schema)->toHaveKey('properties');
        expect($schema['properties'])->toHaveKey('value');
        expect($schema['properties']['value'])->toHaveKey('anyOf');
        expect($schema['properties']['value']['anyOf'])->toHaveCount(2);

        return true;
    });
});

it('supports AnyOfSchema with complex objects', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/generate-structured-anyof-complex');

    $articleSchema = new ObjectSchema(
        'article',
        'A blog article',
        [
            new StringSchema('title', 'Article title'),
            new StringSchema('content', 'Article content'),
        ],
        ['title', 'content']
    );

    $imageSchema = new ObjectSchema(
        'image',
        'An image post',
        [
            new StringSchema('url', 'Image URL'),
            new NumberSchema('width', 'Width in pixels'),
        ],
        ['url']
    );

    $schema = new ObjectSchema(
        'post',
        'Social media post',
        [
            new AnyOfSchema(
                schemas: [$articleSchema, $imageSchema],
                name: 'content',
                description: 'Article or image'
            ),
        ],
        ['content']
    );

    $response = Prism::structured()
        ->using(Provider::VertexAI, 'gemini-2.5-flash')
        ->withSchema($schema)
        ->withPrompt('Create an article post about AI')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKey('content');
    expect($response->structured['content'])->toBeArray();
    expect($response->structured['content'])->toHaveKey('title');
    expect($response->structured['content'])->toHaveKey('content');
    expect($response->structured['content']['title'])->toBe('Understanding AI');
});

it('supports NumberSchema constraints in structured output', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/generate-structured-with-number-constraints');

    $schema = new ObjectSchema(
        'rating',
        'Product rating',
        [
            new NumberSchema(
                name: 'score',
                description: 'Rating score',
                maximum: 5.0,
                minimum: 1.0
            ),
        ],
        ['score']
    );

    $response = Prism::structured()
        ->using(Provider::VertexAI, 'gemini-2.5-flash')
        ->withSchema($schema)
        ->withPrompt('Rate this product 4.5 out of 5')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKey('score');
    expect($response->structured['score'])->toBeFloat();
    expect($response->structured['score'])->toBe(4.5);
    expect($response->structured['score'])->toBeGreaterThanOrEqual(1.0);
    expect($response->structured['score'])->toBeLessThanOrEqual(5.0);
});

it('supports nullable AnyOfSchema in structured output', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/generate-structured-anyof-nullable');

    $schema = new ObjectSchema(
        'response',
        'Response with optional value',
        [
            new AnyOfSchema(
                schemas: [
                    new StringSchema('text', 'A text value'),
                    new NumberSchema('number', 'A numeric value'),
                ],
                name: 'value',
                description: 'Can be text, number, or null',
                nullable: true
            ),
        ],
        ['value']
    );

    $response = Prism::structured()
        ->using(Provider::VertexAI, 'gemini-2.5-flash')
        ->withSchema($schema)
        ->withPrompt('Return a null value')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKey('value');
    expect($response->structured['value'])->toBeNull();
});

it('filters out thought parts when includeThoughts is true', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'vertexai/generate-structured-with-thoughts');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('result', 'The result'),
        ],
        ['result']
    );

    $response = Prism::structured()
        ->using(Provider::VertexAI, 'gemini-2.5-flash')
        ->withSchema($schema)
        ->withPrompt('Extract some information')
        ->withProviderOptions([
            'thinkingConfig' => [
                'thinkingLevel' => 'low',
                'includeThoughts' => true,
            ],
        ])
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKey('result');
    expect($response->structured['result'])->toBe('Successfully extracted information');

    expect($response->steps[0]->additionalContent)->toHaveKey('thoughtSummaries');
    expect($response->steps[0]->additionalContent['thoughtSummaries'])->toBeArray();
    expect($response->steps[0]->additionalContent['thoughtSummaries'][0])->toContain('Let me think about');
});
