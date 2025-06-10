<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Support\Carbon;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Facades\Http;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Http\Request;
use Prism\Prism\Prism;
use Prism\Prism\Providers\Anthropic\Handlers\Text;
use Prism\Prism\Providers\Anthropic\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Messages\Support\Document;
use Prism\Prism\ValueObjects\Messages\Support\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-1234'));
});

it('can generate text with a prompt', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    $response = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-20250514')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->usage->promptTokens)->toBe(11);
    expect($response->usage->completionTokens)->toBeNumeric()->toBeGreaterThan(0);
    expect($response->usage->cacheWriteInputTokens)->toBe(0);
    expect($response->usage->cacheReadInputTokens)->toBe(0);
    expect($response->meta->id)->toStartWith('msg_');
    expect($response->meta->model)->toStartWith('claude-');
    expect($response->text)->not->toBeEmpty();
});

it('can generate text with a system prompt', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-system-prompt');

    $response = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withSystemPrompt('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->usage->promptTokens)->toBe(33);
    expect($response->usage->completionTokens)->toBe(95);
    expect($response->meta->id)->toStartWith('msg_');
    expect($response->meta->model)->toBe('claude-3-5-sonnet-20240620');
    expect($response->text)->not->toBeEmpty();
});

it('can generate text using multiple tools and multiple steps', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/messages',
        'anthropic/generate-text-with-multiple-tools'
    );

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'the city you want the weather for')
            ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        Tool::as('search')
            ->for('useful for searching curret events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
    ];

    $response = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asText();

    // Assert tool calls in the first step
    $firstStep = $response->steps[0];
    expect($firstStep->toolCalls)->toHaveCount(1);
    expect($firstStep->toolCalls[0]->name)->toBe('search');
    expect($firstStep->toolCalls[0]->arguments())->toBe([
        'query' => 'Detroit Tigers game schedule today',
    ]);

    // Assert tool calls in the second step
    $secondStep = $response->steps[1];
    expect($secondStep->toolCalls)->toHaveCount(1);
    expect($secondStep->toolCalls[0]->name)->toBe('weather');
    expect($secondStep->toolCalls[0]->arguments())->toBe([
        'city' => 'Detroit',
    ]);

    // Assert usage
    expect($response->usage->promptTokens)
        ->toBeNumeric()
        ->toBeGreaterThan(0);

    expect($response->usage->completionTokens)
        ->toBeNumeric()
        ->toBeGreaterThan(0);

    // Assert response
    expect($response->meta->id)->toStartWith('msg_');
    expect($response->meta->model)->toBe('claude-3-5-sonnet-20240620');

    // Assert final text content
    expect($response->text)->not->toBeEmpty();
});

it('can send images from file', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/messages',
        'anthropic/generate-text-with-image'
    );

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withMessages([
            new UserMessage(
                'What is this image',
                additionalContent: [
                    Image::fromPath('tests/Fixtures/dimond.png'),
                ],
            ),
        ])
        ->asText();

    Http::assertSent(function (Request $request): true {
        $message = $request->data()['messages'][0]['content'];

        expect($message[0])->toBe([
            'type' => 'text',
            'text' => 'What is this image',
        ]);

        expect($message[1]['type'])->toBe('image');
        expect($message[1]['source']['data'])->toContain(
            base64_encode(file_get_contents('tests/Fixtures/dimond.png'))
        );
        expect($message[1]['source']['media_type'])->toBe('image/png');

        return true;
    });
});

it('handles specific tool choice', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/messages',
        'anthropic/generate-text-with-required-tool-call'
    );

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        Tool::as('search')
            ->for('useful for searching curret events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
    ];

    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withPrompt('Do something')
        ->withTools($tools)
        ->withToolChoice('weather')
        ->asText();

    expect($response->toolCalls[0]->name)->toBe('weather');
});

it('can calculate cache usage correctly', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/messages',
        'anthropic/calculate-cache-usage'
    );

    $response = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withMessages([
            (new UserMessage('New context'))->withProviderOptions(['cacheType' => 'ephemeral']),
        ])
        ->asText();

    expect($response->usage->cacheWriteInputTokens)->toBe(200);
    expect($response->usage->cacheReadInputTokens)->toBe(100);
});

it('adds rate limit data to the responseMeta', function (): void {
    $requests_reset = Carbon::now()->addSeconds(30);

    FixtureResponse::fakeResponseSequence(
        'v1/messages',
        'anthropic/generate-text-with-a-prompt',
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

    $response = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->meta->rateLimits)->toHaveCount(4);
    expect($response->meta->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
    expect($response->meta->rateLimits[0]->name)->toEqual('requests');
    expect($response->meta->rateLimits[0]->limit)->toEqual(1000);
    expect($response->meta->rateLimits[0]->remaining)->toEqual(500);
    expect($response->meta->rateLimits[0]->resetsAt)->toEqual($requests_reset);
});

describe('Anthropic citations', function (): void {
    it('applies the citations request level providerOptions to all documents', function (): void {
        Prism::fake();

        $request = Prism::text()
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

        $payload = Text::buildHttpRequestPayload($request->toRequest());

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

    it('saves message parts with citations to additionalContent on response steps and assistant message for PDF documents', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/messages',
            'anthropic/generate-text-with-pdf-citations'
        );

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
            ->withMessages([
                (new UserMessage(
                    content: 'What does the document say?',
                    additionalContent: [
                        Document::fromPath('tests/Fixtures/test-pdf.pdf'),
                    ]
                )),
            ])
            ->withProviderOptions(['citations' => true])
            ->asText();

        expect($response->text)->not->toBeEmpty();

        expect($response->additionalContent['messagePartsWithCitations'])->toHaveCount(3);
        expect($response->additionalContent['messagePartsWithCitations'][0])->toBeInstanceOf(MessagePartWithCitations::class);

        /** @var MessagePartWithCitations */
        $messagePart = $response->additionalContent['messagePartsWithCitations'][1];

        expect($messagePart->text)->toBe('The Answer to the Ultimate Question of Life, the Universe, and Everything is 42.');
        expect($messagePart->citations)->toHaveCount(1);
        expect($messagePart->citations[0]->type)->toBe('page_location');
        expect($messagePart->citations[0]->citedText)->toBe('The Answer to the Ultimate Question of Life, the Universe, and Everything is 42.');
        expect($messagePart->citations[0]->startIndex)->toBe(1);
        expect($messagePart->citations[0]->endIndex)->toBe(2);
        expect($messagePart->citations[0]->documentIndex)->toBe(0);
        expect($messagePart->citations[0]->documentTitle)->toBeNull();

        expect($response->steps[0]->additionalContent['messagePartsWithCitations'])->toHaveCount(3);
        expect($response->steps[0]->additionalContent['messagePartsWithCitations'][0])
            ->toBeInstanceOf(MessagePartWithCitations::class);

        expect($response->messages->last()->additionalContent['messagePartsWithCitations'])->toHaveCount(3);
        expect($response->steps[0]->additionalContent['messagePartsWithCitations'][0])
            ->toBeInstanceOf(MessagePartWithCitations::class);
    });

    it('saves message parts with citations to additionalContent on response steps and assistant message for text documents', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/messages',
            'anthropic/generate-text-with-text-document-citations'
        );

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->withMessages([
                (new UserMessage(
                    content: 'What color is the grass and sky?',
                    additionalContent: [
                        Document::fromText('The grass is green. The sky is blue.'),
                    ]
                )),
            ])
            ->withProviderOptions(['citations' => true])
            ->asText();

        expect($response->text)->toBe('According to the documents, the grass is green and the sky is blue.');

        expect($response->additionalContent['messagePartsWithCitations'])->toHaveCount(5);
        expect($response->additionalContent['messagePartsWithCitations'][0])
            ->toBeInstanceOf(MessagePartWithCitations::class);

        /** @var MessagePartWithCitations */
        $messagePart = $response->additionalContent['messagePartsWithCitations'][1];

        expect($messagePart->text)->toBe('the grass is green');
        expect($messagePart->citations)->toHaveCount(1);
        expect($messagePart->citations[0]->type)->toBe('char_location');
        expect($messagePart->citations[0]->citedText)->toBe('The grass is green. ');
        expect($messagePart->citations[0]->startIndex)->toBe(0);
        expect($messagePart->citations[0]->endIndex)->toBe(20);
        expect($messagePart->citations[0]->documentIndex)->toBe(0);
        expect($messagePart->citations[0]->documentTitle)->toBeNull();

        expect($response->steps[0]->additionalContent['messagePartsWithCitations'])->toHaveCount(5);
        expect($response->steps[0]->additionalContent['messagePartsWithCitations'][0])
            ->toBeInstanceOf(MessagePartWithCitations::class);

        expect($response->messages->last()->additionalContent['messagePartsWithCitations'])->toHaveCount(5);
        expect($response->steps[0]->additionalContent['messagePartsWithCitations'][0])
            ->toBeInstanceOf(MessagePartWithCitations::class);
    });

    it('saves message parts with citations to additionalContent on response steps and assistant message for custom content documents', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/messages',
            'anthropic/generate-text-with-custom-content-document-citations'
        );

        $response = Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
            ->withMessages([
                (new UserMessage(
                    content: 'What color is the grass and sky?',
                    additionalContent: [
                        Document::fromChunks(['The grass is green.', 'The sky is blue.']),
                    ]
                )),
            ])
            ->withProviderOptions(['citations' => true])
            ->asText();

        expect($response->text)->toBe('The grass is green. The sky is blue.');

        expect($response->additionalContent['messagePartsWithCitations'])->toHaveCount(3);
        expect($response->additionalContent['messagePartsWithCitations'][0])->toBeInstanceOf(MessagePartWithCitations::class);

        /** @var MessagePartWithCitations */
        $messagePart = $response->additionalContent['messagePartsWithCitations'][0];

        expect($messagePart->text)->toBe('The grass is green.');
        expect($messagePart->citations)->toHaveCount(1);
        expect($messagePart->citations[0]->type)->toBe('content_block_location');
        expect($messagePart->citations[0]->citedText)->toBe('The grass is green.');
        expect($messagePart->citations[0]->startIndex)->toBe(0);
        expect($messagePart->citations[0]->endIndex)->toBe(1);

        expect($response->steps[0]->additionalContent['messagePartsWithCitations'])->toHaveCount(3);
        expect($response->steps[0]->additionalContent['messagePartsWithCitations'][0])
            ->toBeInstanceOf(MessagePartWithCitations::class);

        expect($response->messages->last()->additionalContent['messagePartsWithCitations'])->toHaveCount(3);
        expect($response->steps[0]->additionalContent['messagePartsWithCitations'][0])
            ->toBeInstanceOf(MessagePartWithCitations::class);
    });
});

describe('Anthropic extended thinking', function (): void {
    it('can use extending thinking', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-with-extending-thinking');

        $response = Prism::text()
            ->using('anthropic', 'claude-3-7-sonnet-latest')
            ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
            ->withProviderOptions(['thinking' => ['enabled' => true]])
            ->asText();

        expect($response->text)->not->toBeEmpty();

        expect($response->additionalContent['thinking'])->not->toBeEmpty();
        expect($response->additionalContent['thinking_signature'])->not->toBeEmpty();

        expect($response->steps->last()->messages[1])
            ->additionalContent->thinking->not->toBeEmpty()
            ->additionalContent->thinking_signature->not->toBeEmpty();
    });

    it('can override budget tokens', function (): void {
        $response = Prism::text()
            ->using('anthropic', 'claude-3-7-sonnet-latest')
            ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
            ->withProviderOptions([
                'thinking' => [
                    'enabled' => true,
                    'budgetTokens' => 2048,
                ],
            ]);

        $payload = Text::buildHttpRequestPayload($response->toRequest());

        expect(data_get($payload, 'thinking.budget_tokens'))->toBe(2048);
    });

    it('can use extending thinking with tool calls', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/messages',
            'anthropic/text-with-extending-thinking-and-tool-calls'
        );

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'the city you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
            Tool::as('search')
                ->for('useful for searching curret events or data')
                ->withStringParameter('query', 'The detailed search query')
                ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using('anthropic', 'claude-3-7-sonnet-latest')
            ->withTools($tools)
            ->withMaxSteps(3)
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->withProviderOptions(['thinking' => ['enabled' => true]])
            ->asText();

        expect($response->text)->not->toBeEmpty();

        expect($response->steps->first())
            ->additionalContent->thinking->not->toBeEmpty()
            ->additionalContent->thinking_signature->not->toBeEmpty();

        expect($response->steps->first()->messages[1])
            ->additionalContent->thinking->not->toBeEmpty()
            ->additionalContent->thinking_signature->not->toBeEmpty();
    });
});

it('includes anthropic beta header if set in config', function (): void {
    config()->set('prism.providers.anthropic.anthropic_beta', 'beta1,beta2');

    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-with-extending-thinking');

    Prism::text()
        ->using('anthropic', 'claude-3-7-sonnet-latest')
        ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
        ->withProviderOptions(['thinking' => ['enabled' => true]])
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('anthropic-beta')[0] === 'beta1,beta2');
});

describe('exceptions', function (): void {
    it('throws a RateLimitException if the Anthropic responds with a 429', function (): void {
        Http::fake([
            'https://api.anthropic.com/*' => Http::response(
                status: 429,
            ),
        ])->preventStrayRequests();

        Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Hello world!')
            ->asText();

    })->throws(PrismRateLimitedException::class);

    it('sets the correct data on the RateLimitException', function (): void {
        $requests_reset = Carbon::now()->addSeconds(30);

        Http::fake([
            'https://api.anthropic.com/*' => Http::response(
                status: 429,
                headers: [
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
                    'retry-after' => 40,
                ]
            ),
        ])->preventStrayRequests();

        try {
            Prism::text()
                ->using('anthropic', 'claude-3-5-sonnet-20240620')
                ->withPrompt('Hello world!')
                ->asText();
        } catch (PrismRateLimitedException $e) {
            expect($e->retryAfter)->toEqual(40);
            expect($e->rateLimits)->toHaveCount(4);
            expect($e->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
            expect($e->rateLimits[0]->name)->toEqual('requests');
            expect($e->rateLimits[0]->limit)->toEqual(1000);
            expect($e->rateLimits[0]->remaining)->toEqual(500);
            expect($e->rateLimits[0]->resetsAt)->toEqual($requests_reset);

            expect($e->rateLimits[1]->name)->toEqual('input-tokens');
            expect($e->rateLimits[1]->limit)->toEqual(80000);
            expect($e->rateLimits[1]->remaining)->toEqual(0);
        }
    });

    it('throws an overloaded exception if the Anthropic responds with a 529', function (): void {
        Http::fake([
            'https://api.anthropic.com/*' => Http::response(
                status: 529,
            ),
        ])->preventStrayRequests();

        Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Hello world!')
            ->asText();

    })->throws(PrismProviderOverloadedException::class);

    it('throws a request too large exception if the Anthropic responds with a 413', function (): void {
        Http::fake([
            'https://api.anthropic.com/*' => Http::response(
                status: 413,
            ),
        ])->preventStrayRequests();

        Prism::text()
            ->using('anthropic', 'claude-3-5-sonnet-20240620')
            ->withPrompt('Hello world!')
            ->asText();

    })->throws(PrismRequestTooLargeException::class);
});
