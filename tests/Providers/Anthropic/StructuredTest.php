<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Support\Carbon;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Prism;
use Prism\Prism\Providers\Anthropic\Handlers\Structured;
use Prism\Prism\Providers\Anthropic\ValueObjects\MessagePartWithCitations;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Tests\Fixtures\FixtureResponse;

it('returns structured output', function (): void {
    FixtureResponse::fakeResponseSequence('messages', 'anthropic/structured');

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
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withSystemPrompt('The tigers game is at 3pm and the temperature will be 70º')
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

it('adds rate limit data to the responseMeta', function (): void {
    $requests_reset = Carbon::now()->addSeconds(30);

    FixtureResponse::fakeResponseSequence(
        'messages',
        'anthropic/structured',
        [
            'anthropic-ratelimit-requests-limit' => 1000,
            'anthropic-ratelimit-requests-remaining' => 500,
            'anthropic-ratelimit-requests-reset' => $requests_reset->toISOString(),
            'anthropic-ratelimit-input-tokens-limit' => 80000,
            'anthropic-ratelimit-input-tokens-remaining' => 0,
            'anthropic-ratelimit-input-tokens-reset' => Carbon::now()->addSeconds(60)->toISOString(),
            'anthropic-ratelimit-output-tokens-limit' => 16000,
            'anthropic-ratelimit-output-tokens-remaining' => 15000,
            'anthropic-ratelimit-output-tokens-reset' => Carbon::now()->addSeconds(5)->toISOString(),
            'anthropic-ratelimit-tokens-limit' => 96000,
            'anthropic-ratelimit-tokens-remaining' => 15000,
            'anthropic-ratelimit-tokens-reset' => Carbon::now()->addSeconds(5)->toISOString(),
        ]
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
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withSystemPrompt('The tigers game is at 3pm and the temperature will be 70º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    expect($response->meta->rateLimits)->toHaveCount(4);
    expect($response->meta->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
    expect($response->meta->rateLimits[0]->name)->toEqual('requests');
    expect($response->meta->rateLimits[0]->limit)->toEqual(1000);
    expect($response->meta->rateLimits[0]->remaining)->toEqual(500);
    expect($response->meta->rateLimits[0]->resetsAt)->toEqual($requests_reset);
});

it('applies the citations request level providerOptions to all documents', function (): void {
    Prism::fake();

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

    $request = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withMessages([
            (new UserMessage(
                content: 'What color is the grass and sky?',
                additionalContent: [
                    Document::fromText('The grass is green. The sky is blue.'),
                ]
            )),
        ])
        ->withProviderOptions(['citations' => true]);

    $payload = Structured::buildHttpRequestPayload($request->toRequest());

    expect($payload['messages'])->toBe([[
        'role' => 'user',
        'content' => [
            [
                'type' => 'text',
                'text' => 'What color is the grass and sky?',
            ],
            [
                'type' => 'document',
                'citations' => ['enabled' => true],
                'source' => [
                    'type' => 'text',
                    'media_type' => 'text/plain',
                    'data' => 'The grass is green. The sky is blue.',
                ],
            ],
        ],
    ]]);
});

it('saves message parts with citations to additionalContent on response steps and assistant message for text documents', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-structured-with-text-document-citations');

    $response = Prism::structured()
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withMessages([
            new UserMessage(
                content: 'Is the grass green and the sky blue?',
                additionalContent: [
                    Document::fromChunks(['The grass is green.', 'Flamingos are pink.', 'The sky is blue.']),
                ]
            ),
        ])
        ->withSchema(new ObjectSchema('body', '', [new BooleanSchema('answer', '')], ['answer']))
        ->withProviderOptions(['citations' => true])
        ->asStructured();

    expect($response->structured)->toBe(['answer' => true]);

    expect($response->additionalContent['messagePartsWithCitations'])->toHaveCount(1);
    expect($response->additionalContent['messagePartsWithCitations'][0])->toBeInstanceOf(MessagePartWithCitations::class);

    /** @var MessagePartWithCitations */
    $messagePart = $response->additionalContent['messagePartsWithCitations'][0];

    expect($messagePart->text)->toBe('{"answer": true}');
    expect($messagePart->citations)->toHaveCount(2);
    expect($messagePart->citations[0]->type)->toBe('content_block_location');
    expect($messagePart->citations[0]->citedText)->toBe('The grass is green.');
    expect($messagePart->citations[0]->startIndex)->toBe(0);
    expect($messagePart->citations[0]->endIndex)->toBe(1);
    expect($messagePart->citations[0]->documentIndex)->toBe(0);

    expect($response->steps[0]->additionalContent['messagePartsWithCitations'])->toHaveCount(1);
    expect($response->steps[0]->additionalContent['messagePartsWithCitations'][0])->toBeInstanceOf(MessagePartWithCitations::class);

    expect($response->responseMessages->last()->additionalContent['messagePartsWithCitations'])->toHaveCount(1);
    expect($response->steps[0]->additionalContent['messagePartsWithCitations'][0])->toBeInstanceOf(MessagePartWithCitations::class);
});

it('can use extending thinking', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-with-extending-thinking');

    $response = Prism::structured()
        ->using('anthropic', 'claude-3-7-sonnet-latest')
        ->withSchema(new ObjectSchema('output', 'the output object', [new StringSchema('text', 'the output text')], ['text']))
        ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
        ->withProviderOptions(['thinking' => ['enabled' => true]])
        ->asStructured();

    $expected_signature = 'ErUBCkYIBRgCIkDq/0akQPXjxyUDNYIEJDtoKAHfurgVvrSZvNZQ7K3QThwLAimdSKQvOP0FgzhRoOqKULqGMFxL37M87In0nFi/Egym1dqOTMScK1ZMjOUaDP0c8511Tm80nCJ6qCIwv126afgqa9mTvKJUlckcL4xVXvr9rtlWsqHmtnf2FnAj30h6SprQ2vtoalzQIwyHKh3wnlXkTFHhGyFc/EqMCorZ3qmWMr6zYCKCbojWzxgC';

    expect($response->structured['text'])->toContain('Douglas Adams');
    expect($response->additionalContent['thinking'])->toContain('meaning of life');
    expect($response->additionalContent['thinking_signature'])->toBe($expected_signature);

    expect($response->steps->last()->messages[2])
        ->additionalContent->thinking->toContain('meaning of life')
        ->additionalContent->thinking_signature->toBe($expected_signature);
});

it('throws error when citations and tool calling are used together', function (): void {
    $schema = new ObjectSchema('output', 'the output object', [
        new StringSchema('answer', 'The answer'),
    ], ['answer']);

    expect(fn (): \Prism\Prism\Structured\Response => Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withPrompt('What is the answer?')
        ->withProviderOptions(['citations' => true, 'use_tool_calling' => true])
        ->asStructured()
    )->toThrow(PrismException::class, 'Citations are not supported with tool calling mode');
});

it('returns structured output with default JSON mode', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/messages',
        'anthropic/structured-with-default-json'
    );

    $schema = new ObjectSchema('output', 'the output object', [
        new StringSchema('answer', 'A simple answer'),
    ], ['answer']);

    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withPrompt('What is 2+2?')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKey('answer');
    expect($response->structured['answer'])->toBeString();
});

it('works with thinking mode when use_tool_calling is true', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/messages',
        'anthropic/structured-with-use-tool-calling'
    );

    $schema = new ObjectSchema('output', 'the output object', [
        new StringSchema('answer', 'The answer about life, universe and everything'),
    ], ['answer']);

    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
        ->withSystemPrompt('You are a helpful assistant.')
        ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
        ->withProviderOptions(['thinking' => ['enabled' => true], 'use_tool_calling' => true])
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKey('answer');
    expect($response->structured['answer'])->toBeString();

    expect($response->additionalContent)->toHaveKey('thinking');
    expect($response->additionalContent['thinking'])->toBeString();
    expect($response->additionalContent['thinking_signature'])->toBeString();
});

it('handles Chinese output with double quotes using tool calling', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-chinese-tool-calling');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast in Chinese with double quotes for temperature'),
            new StringSchema('recommendation', 'Clothing recommendation in Chinese with quoted items'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'recommendation', 'coat_required']
    );

    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withSystemPrompt('Respond in Chinese. Use double quotes around temperature values and clothing items.')
        ->withPrompt('What is the weather like today and what should I wear? The temperature is 15°C.')
        ->withProviderOptions(['use_tool_calling' => true])
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'weather',
        'recommendation',
        'coat_required',
    ]);
    expect($response->structured['weather'])->toBeString();
    expect($response->structured['recommendation'])->toBeString();
    expect($response->structured['coat_required'])->toBeBool();

    expect($response->structured['weather'])->toContain('今');
    expect($response->structured['weather'])->toContain('15°C');
    expect($response->structured['recommendation'])->toContain('建');
    expect($response->structured['coat_required'])->toBe(true);
});
