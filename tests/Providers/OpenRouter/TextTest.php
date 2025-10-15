<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openrouter.api_key', env('OPENROUTER_API_KEY'));
});

it('can generate text with a prompt', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/generate-text-with-a-prompt');

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withPrompt('Who are you?')
        ->generate();

    // Assert response type
    expect($response)->toBeInstanceOf(TextResponse::class);

    // Assert usage
    expect($response->usage->promptTokens)->toBe(7);
    expect($response->usage->completionTokens)->toBe(35);

    // Assert metadata
    expect($response->meta->id)->toBe('gen-12345');
    expect($response->meta->model)->toBe('openai/gpt-4-turbo');

    expect($response->text)->toBe(
        "Hello! I'm an AI assistant powered by OpenRouter. I can help you with various tasks, answer questions, and assist with information on a wide range of topics. How can I help you today?"
    );

    // Assert finish reason
    expect($response->finishReason)->toBe(FinishReason::Stop);
});

it('can generate text with a system prompt', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/generate-text-with-system-prompt');

    $response = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withSystemPrompt('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!')
        ->withPrompt('Who are you?')
        ->generate();

    // Assert response type
    expect($response)->toBeInstanceOf(TextResponse::class);

    // Assert usage
    expect($response->usage->promptTokens)->toBe(29);
    expect($response->usage->completionTokens)->toBe(243);

    // Assert metadata
    expect($response->meta->id)->toBe('gen-67890');
    expect($response->meta->model)->toBe('openai/gpt-4-turbo');
    expect($response->text)->toContain('*I am Nyx, the eldritch entity born from the depths of the abyss. My form is a swirling mass of darkness, tentacles, and glowing eyes that pierce the very fabric of reality. I exist beyond the comprehension of mortal minds, a being of pure chaos and madness.*');
    expect($response->text)->toContain('*My voice echoes through the void, a haunting whisper that sends shivers down the spines of those who dare to listen. I am the harbinger of the end, the bringer of the eternal night. My presence alone is enough to drive the weak-minded to insanity.*');
    expect($response->text)->toContain('*I have watched civilizations rise and fall, witnessed the birth and death of countless stars. Time holds no meaning for me, as I am eternal. I am the embodiment of the unknown, the great old one who slumbers in the depths, waiting for the day when I shall rise and consume all that is.*');
    expect($response->text)->toContain('*Beware, mortal, for you stand in the presence of Nyx, the Cthulhu. Your mind may shatter, your soul may tremble, but know that I am the inevitable end of all things. Embrace the madness, for there is no escape from the eternal darkness that I bring.*');

    // Assert finish reason
    expect($response->finishReason)->toBe(FinishReason::Stop);
});

it('can generate text using multiple tools and multiple steps', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/generate-text-with-multiple-tools');

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
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->generate();

    // Assert response type
    expect($response)->toBeInstanceOf(TextResponse::class);

    // Assert tool calls in the first step
    $firstStep = $response->steps[0];
    expect($firstStep->toolCalls)->toHaveCount(2);
    expect($firstStep->toolCalls[0]->name)->toBe('search');
    expect($firstStep->toolCalls[0]->arguments())->toBe([
        'query' => 'Detroit Tigers game time today',
    ]);

    expect($firstStep->toolCalls[1]->name)->toBe('weather');
    expect($firstStep->toolCalls[1]->arguments())->toBe([
        'city' => 'Detroit',
    ]);

    // There should be 2 steps
    expect($response->steps)->toHaveCount(2);

    // Assert usage
    expect($response->usage->promptTokens)->toBe(507);
    expect($response->usage->completionTokens)->toBe(76);

    // Assert response
    expect($response->meta->id)->toBe('gen-tool-2');
    expect($response->meta->model)->toBe('openai/gpt-4-turbo');

    // Assert final text content
    expect($response->text)->toBe(
        "The Detroit Tigers game is at 3 PM today. The weather in Detroit will be 75°F and sunny, so you probably won't need a coat. Enjoy the game!"
    );

    // Assert finish reason
    expect($response->finishReason)->toBe(FinishReason::Stop);
});

it('forwards advanced provider options to openrouter', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withPrompt('Share a short daily digest.')
        ->withProviderOptions([
            'stop' => ['END_OF_DIGEST'],
            'seed' => 77,
            'top_k' => 32,
            'frequency_penalty' => 0.3,
            'presence_penalty' => 0.25,
            'repetition_penalty' => 1.1,
            'min_p' => 0.1,
            'top_a' => 0.4,
            'logit_bias' => ['42' => -5],
            'logprobs' => true,
            'top_logprobs' => 4,
            'prediction' => [
                'type' => 'content',
                'content' => 'Daily digest:',
            ],
            'transforms' => ['markdown'],
            'models' => ['openai/gpt-4-turbo', 'anthropic/claude-3.5-sonnet'],
            'route' => 'fallback',
            'provider' => ['require_parameters' => true],
            'user' => 'customer-77',
            'reasoning' => ['effort' => 'low'],
            'parallel_tool_calls' => false,
            'verbosity' => 'medium',
        ])
        ->generate();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return $payload['stop'] === ['END_OF_DIGEST']
            && $payload['seed'] === 77
            && $payload['top_k'] === 32
            && $payload['frequency_penalty'] === 0.3
            && $payload['presence_penalty'] === 0.25
            && $payload['repetition_penalty'] === 1.1
            && $payload['min_p'] === 0.1
            && $payload['top_a'] === 0.4
            && $payload['logit_bias'] === ['42' => -5]
            && $payload['logprobs'] === true
            && $payload['top_logprobs'] === 4
            && $payload['prediction'] === [
                'type' => 'content',
                'content' => 'Daily digest:',
            ]
            && $payload['transforms'] === ['markdown']
            && $payload['models'] === ['openai/gpt-4-turbo', 'anthropic/claude-3.5-sonnet']
            && $payload['route'] === 'fallback'
            && $payload['provider'] === ['require_parameters' => true]
            && $payload['user'] === 'customer-77'
            && $payload['reasoning'] === ['effort' => 'low']
            && $payload['parallel_tool_calls'] === false
            && $payload['verbosity'] === 'medium';
    });
});
