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

it('applies the citations request level providerMeta to all documents', function (): void {
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
        ->withProviderMeta(Provider::Anthropic, ['citations' => true]);

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
        ->withProviderMeta(Provider::Anthropic, ['citations' => true])
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
        ->withProviderMeta(Provider::Anthropic, ['thinking' => ['enabled' => true]])
        ->asStructured();

    $expected_thinking = "The question asks about \"the meaning of life, the universe and everything in popular fiction.\" This is a reference to Douglas Adams' \"The Hitchhiker's Guide to the Galaxy\" series, where a supercomputer named Deep Thought calculates that the answer to \"the ultimate question of life, the universe, and everything\" is 42.\n\nI'm being asked to respond with only JSON that matches a specific schema. The schema requires an object with a property called \"text\" that contains a string, and no additional properties are allowed.\n\nSo I should create a JSON object with a \"text\" property that explains that in popular fiction, particularly in \"The Hitchhiker's Guide to the Galaxy,\" the meaning of life, the universe, and everything is famously presented as \"42.\"";
    $expected_signature = 'EuYBCkQYAiJAg+qtLhMXqUgaxagF5ryu2/sYLIpErjJsELoN95UARnscajTu5YXcRzTTEbiH87YC8xd5X6SRRxA5FzEiyPbzZBIMO8q4u82TeKNXUtxmGgzNMkFq/WSx5ByDvjsiMHy61qKH+/fr9bFMAjSR4T9dXYIK2G/j2xQSwi5hocmvqW8zXu8Xc5LLqxvZGXGq4ipQyZTLiVn0nQvLXf5qf5RxnbAvCc+NGHezUbUGFGIZsbiScvW8XMvbHPPCkofZqMbYmXssXpHsDkr7LgAz2gMY7tGD6+0Jwxy+YnmVo1J2bj0=';

    expect($response->structured['text'])->toBe("In popular fiction, the most famous answer to the meaning of life, the universe, and everything is '42' from Douglas Adams' 'The Hitchhiker's Guide to the Galaxy.' In this science fiction series, a supercomputer called Deep Thought spends 7.5 million years calculating this answer, though unfortunately, no one knows what the actual question was.");
    expect($response->additionalContent['thinking'])->toBe($expected_thinking);
    expect($response->additionalContent['thinking_signature'])->toBe($expected_signature);

    expect($response->steps->last()->messages[2])
        ->additionalContent->thinking->toBe($expected_thinking)
        ->additionalContent->thinking_signature->toBe($expected_signature);
});
