<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', env('GEMINI_API_KEY', 'test-api-key'));
});

describe('Structured output with tools for Gemini', function (): void {
    it('can generate structured output with a single tool', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'gemini/structured-with-single-tool');

        $schema = new ObjectSchema(
            'weather_analysis',
            'Analysis of weather conditions',
            [
                new StringSchema('summary', 'A summary of the weather', true),
                new StringSchema('recommendation', 'A recommendation based on weather', true),
            ],
            ['summary', 'recommendation']
        );

        $weatherTool = (new Tool)
            ->as('get_weather')
            ->for('Get current weather for a location')
            ->withStringParameter('location', 'The city and state')
            ->using(fn (string $location): string => "Weather in {$location}: 72°F, sunny");

        $response = Prism::structured()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withSchema($schema)
            ->withTools([$weatherTool])
            ->withMaxSteps(3)
            ->withPrompt('What is the weather in San Francisco and should I wear a coat?')
            ->asStructured();

        // Verify we have structured output
        expect($response->structured)->toBeArray()
            ->and($response->structured)->toHaveKeys(['summary', 'recommendation'])
            ->and($response->structured['summary'])->toBeString()
            ->and($response->structured['recommendation'])->toBeString();

        // Verify tool was called
        expect($response->toolCalls)->toBeArray();
        if (count($response->toolCalls) > 0) {
            expect($response->toolCalls[0]->name)->toBe('get_weather');
            expect($response->toolCalls[0]->arguments())->toHaveKey('location');
        }

        // Verify tool results exist if tool was called
        if (count($response->toolCalls) > 0) {
            expect($response->toolResults)->toBeArray();
            expect($response->toolResults[0]->toolName)->toBe('get_weather');
            expect($response->toolResults[0]->result)->toBeString();
        }

        // Verify final step has structured data
        $finalStep = $response->steps->last();
        expect($finalStep->finishReason)->toBe(FinishReason::Stop);
    });

    it('can generate structured output with multiple tools and multi-step execution', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'gemini/structured-with-multiple-tools');

        $schema = new ObjectSchema(
            'game_analysis',
            'Analysis of game time and weather',
            [
                new StringSchema('game_time', 'The time of the game', true),
                new StringSchema('weather_summary', 'Summary of weather conditions', true),
                new StringSchema('recommendation', 'Recommendation on what to wear', true),
            ],
            ['game_time', 'weather_summary', 'recommendation']
        );

        $tools = [
            (new Tool)
                ->as('get_weather')
                ->for('Get current weather for a location')
                ->withStringParameter('city', 'The city name')
                ->using(fn (string $city): string => "Weather in {$city}: 45°F and cold"),
            (new Tool)
                ->as('search_games')
                ->for('Search for game times in a city')
                ->withStringParameter('city', 'The city name')
                ->using(fn (string $city): string => 'The Tigers game is at 3pm in Detroit'),
        ];

        $response = Prism::structured()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withSchema($schema)
            ->withTools($tools)
            ->withMaxSteps(5)
            ->withPrompt('What time is the Tigers game today in Detroit and should I wear a coat?')
            ->asStructured();

        // Verify structured output
        expect($response->structured)->toBeArray()
            ->and($response->structured)->toHaveKeys(['game_time', 'weather_summary', 'recommendation']);

        // Verify tool calls and results exist
        expect($response->toolCalls)->toBeArray();
        expect($response->toolResults)->toBeArray();

        // Verify we have at least one step
        expect($response->steps)->not()->toBeEmpty();

        // Verify final step
        $finalStep = $response->steps->last();
        expect($finalStep->structured)->toBeArray();
    });

    it('returns structured output immediately when no tool calls needed', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'gemini/structured-without-tool-calls');

        $schema = new ObjectSchema(
            'analysis',
            'Simple analysis',
            [
                new StringSchema('answer', 'The answer', true),
            ],
            ['answer']
        );

        $weatherTool = (new Tool)
            ->as('get_weather')
            ->for('Get weather for a location')
            ->withStringParameter('location', 'The location')
            ->using(fn (string $location): string => "Weather data for {$location}");

        $response = Prism::structured()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withSchema($schema)
            ->withTools([$weatherTool])
            ->withPrompt('What is 2 + 2?')
            ->asStructured();

        // Verify structured output
        expect($response->structured)->toBeArray()
            ->and($response->structured)->toHaveKey('answer')
            ->and($response->structured['answer'])->toBeString();

        // Verify no tool calls were made (simple math doesn't need weather tool)
        expect($response->toolCalls)->toBeArray();

        // Verify only one step (no tool execution needed)
        expect($response->steps)->toHaveCount(1);
    });
});
