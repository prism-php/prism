<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;
use Tests\Fixtures\FixtureResponse;

it('can call a single tool and return final response', function (): void {
    FixtureResponse::fakeResponseSequence('predictions', 'replicate/tool-call-single');

    $weatherCalled = false;

    $tools = [
        (new Tool)->as('get_weather')
            ->for('Get current weather for a location')
            ->withStringParameter('location', 'The city name')
            ->using(function (string $location) use (&$weatherCalled): string {
                $weatherCalled = true;
                expect($location)->toBe('Paris');

                return 'Sunny, 72°F in Paris';
            }),
    ];

    $response = Prism::text()
        ->using(Provider::Replicate, 'meta/meta-llama-3.1-8b-instruct')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('What is the weather in Paris?')
        ->generate();

    // Assert tool was called
    expect($weatherCalled)->toBeTrue();

    // Assert we have steps
    expect($response->steps)->toHaveCount(2);

    // Assert first step has tool call
    expect($response->steps[0]->finishReason)->toBe(FinishReason::ToolCalls)
        ->and($response->steps[0]->toolCalls)->toHaveCount(1)
        ->and($response->steps[0]->toolCalls[0]->name)->toBe('get_weather')
        ->and($response->steps[0]->toolCalls[0]->arguments())->toBe(['location' => 'Paris'])
        ->and($response->steps[0]->toolResults)->toHaveCount(1)
        ->and($response->steps[0]->toolResults[0]->result)->toBe('Sunny, 72°F in Paris');

    // Assert second step has final response
    expect($response->steps[1]->finishReason)->toBe(FinishReason::Stop)
        ->and($response->steps[1]->text)->toContain('72°F')
        ->and($response->steps[1]->text)->toContain('Paris');

    // Assert final response
    expect($response->text)->toContain('72°F');
    expect($response->finishReason)->toBe(FinishReason::Stop);
});

it('can call multiple tools in parallel', function (): void {
    FixtureResponse::fakeResponseSequence('predictions', 'replicate/tool-call-multiple');

    $weatherCalled = false;
    $searchCalled = false;

    $tools = [
        (new Tool)->as('get_weather')
            ->for('Get weather')
            ->withStringParameter('city', 'City name')
            ->using(function (string $city) use (&$weatherCalled): string {
                $weatherCalled = true;

                return "72°F in {$city}";
            }),
        (new Tool)->as('search')
            ->for('Search events')
            ->withStringParameter('query', 'Search query')
            ->using(function (string $query) use (&$searchCalled): string {
                $searchCalled = true;

                return 'Event at 3pm';
            }),
    ];

    $response = Prism::text()
        ->using(Provider::Replicate, 'meta/meta-llama-3.1-8b-instruct')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('What is the weather and are there any events today?')
        ->generate();

    expect($weatherCalled)->toBeTrue()
        ->and($searchCalled)->toBeTrue();

    expect($response->steps[0]->toolCalls)->toHaveCount(2)
        ->and($response->steps[0]->toolResults)->toHaveCount(2);
});

it('handles multi-step tool calling', function (): void {
    FixtureResponse::fakeResponseSequence('predictions', 'replicate/tool-call-multistep');

    $callOrder = [];

    $tools = [
        (new Tool)->as('get_location')
            ->for('Get user location')
            ->using(function () use (&$callOrder): string {
                $callOrder[] = 'location';

                return 'Paris';
            }),
        (new Tool)->as('get_weather')
            ->for('Get weather')
            ->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$callOrder): string {
                $callOrder[] = 'weather';

                return "72°F in {$city}";
            }),
    ];

    $response = Prism::text()
        ->using(Provider::Replicate, 'meta/meta-llama-3.1-8b-instruct')
        ->withTools($tools)
        ->withMaxSteps(5)
        ->withPrompt('What is the weather at my location?')
        ->generate();

    expect($callOrder)->toBe(['location', 'weather'])
        ->and($response->steps)->toHaveCount(3); // location, weather, final
});

it('handles when model does not use tools', function (): void {
    FixtureResponse::fakeResponseSequence('predictions', 'replicate/tool-call-no-tools');

    $toolCalled = false;

    $tools = [
        (new Tool)->as('get_weather')
            ->for('Get weather')
            ->withStringParameter('city', 'City')
            ->using(function () use (&$toolCalled): string {
                $toolCalled = true;

                return 'Sunny';
            }),
    ];

    $response = Prism::text()
        ->using(Provider::Replicate, 'meta/meta-llama-3.1-8b-instruct')
        ->withTools($tools)
        ->withPrompt('Just say hello')
        ->generate();

    expect($toolCalled)->toBeFalse()
        ->and($response->steps)->toHaveCount(1)
        ->and($response->steps[0]->toolCalls)->toBeEmpty()
        ->and(strtolower($response->text))->toContain('hello');
});

it('stops at max steps', function (): void {
    FixtureResponse::fakeResponseSequence('predictions', 'replicate/tool-call-multistep');

    $tools = [
        (new Tool)->as('get_location')
            ->for('Get user location')
            ->using(fn (): string => 'Paris'),
        (new Tool)->as('get_weather')
            ->for('Get weather')
            ->withStringParameter('city', 'City')
            ->using(fn (string $city): string => "72°F in {$city}"),
    ];

    $response = Prism::text()
        ->using(Provider::Replicate, 'meta/meta-llama-3.1-8b-instruct')
        ->withTools($tools)
        ->withMaxSteps(2)
        ->withPrompt('What is the weather at my location?')
        ->generate();

    expect($response->steps)->toHaveCount(2);
});

it('throws exception for unknown tool', function (): void {
    FixtureResponse::fakeResponseSequence('predictions', 'replicate/tool-call-single');

    // Model tries to call 'get_weather' but we don't provide it
    $tools = [
        (new Tool)->as('different_tool')
            ->for('Different tool')
            ->using(fn (): string => 'result'),
    ];

    expect(fn () => Prism::text()
        ->using(Provider::Replicate, 'meta/meta-llama-3.1-8b-instruct')
        ->withTools($tools)
        ->withPrompt('What is the weather?')
        ->generate()
    )->toThrow(PrismException::class);
});

it('handles tool execution errors', function (): void {
    FixtureResponse::fakeResponseSequence('predictions', 'replicate/tool-call-single');

    $tools = [
        (new Tool)->as('get_weather')
            ->for('Get weather')
            ->withStringParameter('location', 'Location')
            ->withoutErrorHandling()
            ->using(function (): string {
                throw new \RuntimeException('API connection failed');
            }),
    ];

    expect(fn () => Prism::text()
        ->using(Provider::Replicate, 'meta/meta-llama-3.1-8b-instruct')
        ->withTools($tools)
        ->withPrompt('What is the weather?')
        ->generate()
    )->toThrow(PrismException::class);
});

it('tracks usage across tool calls', function (): void {
    FixtureResponse::fakeResponseSequence('predictions', 'replicate/tool-call-single');

    $tools = [
        (new Tool)->as('get_weather')
            ->for('Get weather')
            ->withStringParameter('location', 'Location')
            ->using(fn (string $location): string => 'Sunny'),
    ];

    $response = Prism::text()
        ->using(Provider::Replicate, 'meta/meta-llama-3.1-8b-instruct')
        ->withTools($tools)
        ->withPrompt('Weather in Paris?')
        ->generate();

    expect($response->usage->promptTokens)->toBeInt()
        ->and($response->usage->completionTokens)->toBeInt();
});
