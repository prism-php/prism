<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Support\Carbon;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Providers\Anthropic\Handlers\Structured;
use Prism\Prism\Providers\Anthropic\ValueObjects\MessagePartWithCitations;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Messages\Support\Document;
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
                'source' => [
                    'type' => 'text',
                    'media_type' => 'text/plain',
                    'data' => 'The grass is green. The sky is blue.',
                ],
                'citations' => ['enabled' => true],
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

    expect($response->structured['text'])->not->toBeEmpty();
    expect($response->additionalContent['thinking'])->not->toBeEmpty();
    expect($response->additionalContent['thinking_signature'])->not->toBeEmpty();

    expect($response->steps->last()->messages[2])
        ->additionalContent->thinking->not->toBeEmpty()
        ->additionalContent->thinking_signature->not->toBeEmpty();
});
