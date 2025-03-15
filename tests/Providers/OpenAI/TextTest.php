<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY'));
});

it('can generate text with a prompt', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/chat/completions',
        'openai/generate-text-with-a-prompt'
    );

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->usage->promptTokens)
        ->toBeNumeric()
        ->toBeGreaterThan(0);
    expect($response->usage->completionTokens)
        ->toBeNumeric()
        ->toBeGreaterThan(0);
    expect($response->meta->id)->toContain('chatcmpl-');
    expect($response->meta->model)->toContain('gpt-4o');
    expect($response->text)->toBeString();
});

it('can generate text with a system prompt', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/chat/completions',
        'openai/generate-text-with-system-prompt'
    );

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withSystemPrompt('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->usage->promptTokens)
        ->toBeNumeric()
        ->toBeGreaterThan(20);
    expect($response->usage->completionTokens)
        ->toBeNumeric()
        ->toBeGreaterThan(20);
    expect($response->meta->id)->toContain('chatcmpl-');
    expect($response->meta->model)->toContain('gpt-4o');
    expect($response->text)
        ->toBeString()
        ->toContain('Nyx');
});

it('can generate text using multiple tools and multiple steps', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/chat/completions',
        'openai/generate-text-with-multiple-tools'
    );

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather in {$city} will be 75° and sunny"),
        Tool::as('search')
            ->for('useful for searching curret events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is today at 3pm in detroit'),
    ];

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withTools($tools)
        ->usingTemperature(0)
        ->withMaxSteps(3)
        ->withSystemPrompt('Current Date: '.now()->toDateString())
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asText();

    // Assert tool calls in the first step
    $firstStep = $response->steps[0];
    expect($firstStep->toolCalls)->toHaveCount(2);
    expect($firstStep->toolCalls[0]->name)->toBe('search');
    expect($firstStep->toolCalls[0]->arguments())->toBe([
        'query' => 'Detroit Tigers game March 14 2025 time',
    ]);

    expect($firstStep->toolCalls[1]->name)->toBe('weather');
    expect($firstStep->toolCalls[1]->arguments())->toBe([
        'city' => 'Detroit',
    ]);

    expect($response->usage->promptTokens)->toBeNumeric();
    expect($response->usage->completionTokens)->toBeNumeric();

    // Assert response
    expect($response->meta->id)->toContain('chatcmpl-');
    expect($response->meta->model)->toContain('gpt-4o');

    // Assert final text content
    expect($response->text)->toBe(
        "The Detroit Tigers game is today at 3 PM in Detroit. The weather in Detroit will be 75°F and sunny, so you won't need a coat!"
    );
});

it('sends the organization header when set', function (): void {
    config()->set('prism.providers.openai.organization', 'echolabs');

    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('OpenAI-Organization')[0] === 'echolabs');
});

it('does not send the organization header if one is not given', function (): void {
    config()->offsetUnset('prism.providers.openai.organization');

    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => empty($request->header('OpenAI-Organization')));
});

it('sends the api key header when set', function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-1234');

    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'Bearer sk-1234');
});

it('does not send the api key header', function (): void {
    config()->offsetUnset('prism.providers.openai.api_key');

    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();
    Http::assertSent(fn (Request $request): bool => empty($request->header('Authorization')));
});

it('sends the project header when set', function (): void {
    config()->set('prism.providers.openai.project', 'echolabs');

    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('OpenAI-Project')[0] === 'echolabs');
});

it('does not send the project header if one is not given', function (): void {
    config()->offsetUnset('prism.providers.openai.project');

    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => empty($request->header('OpenAI-Project')));
});

it('handles specific tool choice', function (): void {
    FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/generate-text-with-required-tool-call');

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
        ->using('openai', 'gpt-4o')
        ->withPrompt('Do something')
        ->withTools($tools)
        ->withToolChoice('weather')
        ->asText();

    expect($response->toolCalls[0]->name)->toBe('weather');
});

it('throws an exception for ToolChoice::Any', function (): void {
    $this->expectException(PrismException::class);
    $this->expectExceptionMessage('Invalid tool choice');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->withToolChoice(ToolChoice::Any)
        ->asText();
});

it('sets the rate limits on meta', function (): void {
    $this->freezeTime(function (Carbon $time): void {
        $time = $time->toImmutable();

        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openai/generate-text-with-a-prompt', [
            'x-ratelimit-limit-requests' => 60,
            'x-ratelimit-limit-tokens' => 150000,
            'x-ratelimit-remaining-requests' => 0,
            'x-ratelimit-remaining-tokens' => 149984,
            'x-ratelimit-reset-requests' => '1s',
            'x-ratelimit-reset-tokens' => '6m30s',
        ]);

        $response = Prism::text()
            ->using('openai', 'gpt-4o')
            ->withPrompt('Who are you?')
            ->asText();

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
