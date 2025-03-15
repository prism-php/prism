<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

it('returns structured output', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/structured-structured-mode');

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
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'weather',
        'game_time',
        'coat_required',
    ]);
    expect($response->structured['weather'])->toBeString();
    expect($response->structured['game_time'])->toBeString();
    expect($response->structured['coat_required'])->toBeBool();
});

it('returns structured output using json mode', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/structured-json-mode');

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
        ->using(Provider::OpenAI, 'gpt-4-turbo')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'weather',
        'game_time',
        'coat_required',
    ]);
    expect($response->structured['weather'])->toBeString();
    expect($response->structured['game_time'])->toBeString();
    expect($response->structured['coat_required'])->toBeBool();
});

it('schema strict defaults to null', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/strict-schema-defaults');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
    );

    $response = Prism::structured()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withSchema($schema)
        ->withSystemPrompt('The game time is 3pm and the weather is 80° and sunny')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        expect(array_keys(data_get($body, 'response_format.json_schema')))->not->toContain('strict');

        return true;
    });
});

it('uses meta to define strict mode', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/chat/completions',
        'openai/strict-schema-setting-set'
    );

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
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withSchema($schema)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->withProviderMeta(Provider::OpenAI, [
            'schema' => ['strict' => true],
        ])
        ->asStructured();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        expect(data_get($body, 'response_format.json_schema.strict'))->toBeTrue();

        return true;
    });
});

it('throws an exception when there is a refusal', function (): void {
    $this->expectException(PrismException::class);
    $this->expectExceptionMessage('OpenAI Refusal: Could not process your request');

    Http::fake([
        'v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'refusal' => 'Could not process your request',
                ],
            ]],
        ]),
    ]);

    Http::preventStrayRequests();

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

    Prism::structured()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withSchema($schema)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        expect(data_get($body, 'response_format.json_schema.strict'))->toBeTrue();

        return true;
    });
});

it('throws an exception for o1 models', function (string $model): void {
    $this->expectException(PrismException::class);
    $this->expectExceptionMessage(sprintf('Structured output is not supported for %s', $model));

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
    );

    Prism::structured()
        ->using(Provider::OpenAI, $model)
        ->withSchema($schema)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();
})->with([
    'o1-mini',
    'o1-mini-2024-09-12',
    'o1-preview',
    'o1-preview-2024-09-12',
]);

it('sets the rate limits on meta', function (): void {
    $this->freezeTime(function (Carbon $time): void {
        $time = $time->toImmutable();

        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/structured-structured-mode', [
            'x-ratelimit-limit-requests' => 60,
            'x-ratelimit-limit-tokens' => 150000,
            'x-ratelimit-remaining-requests' => 0,
            'x-ratelimit-remaining-tokens' => 149984,
            'x-ratelimit-reset-requests' => '1s',
            'x-ratelimit-reset-tokens' => '6m30s',
        ]);

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
            ->using(Provider::OpenAI, 'gpt-4o')
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->asStructured();

        expect($response->meta->rateLimits)->toHaveCount(2);
        expect($response->meta->rateLimits[0]->name)->toEqual('requests');
        expect($response->meta->rateLimits[0]->limit)->toEqual(60);
        expect($response->meta->rateLimits[0]->remaining)->toEqual(0);
        expect($response->meta->rateLimits[0]->resetsAt->equalTo(now()->addSeconds(1)))->toBeTrue();
        expect($response->meta->rateLimits[1]->name)->toEqual('tokens');
        expect($response->meta->rateLimits[1]->limit)->toEqual(150000);
        expect($response->meta->rateLimits[1]->remaining)->toEqual(149984);
        expect($response->meta->rateLimits[1]->resetsAt->equalTo(now()->addMinutes(6)->addSeconds(30)))->toBeTrue();
    });
});
