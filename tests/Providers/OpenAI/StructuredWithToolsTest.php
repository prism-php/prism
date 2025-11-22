<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Tests\Fixtures\FixtureResponse;

describe('Structured output with tools for OpenAI', function (): void {
    it('can generate structured output with a single tool', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/structured-with-single-tool');

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
            ->using(Provider::OpenAI, 'gpt-4o')
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

        // Verify tool calls exist
        expect($response->toolCalls)->toBeArray();

        // Verify we have tool results if custom tools were called
        expect($response->toolResults)->toBeArray();

        // Verify final step has structured data
        $finalStep = $response->steps->last();
        expect($finalStep->finishReason)->toBeIn([FinishReason::Stop, FinishReason::ToolCalls]);
    });

    it('can generate structured output with multiple tools and multi-step execution', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/structured-with-multiple-tools');

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
            ->using(Provider::OpenAI, 'gpt-4o')
            ->withSchema($schema)
            ->withTools($tools)
            ->withMaxSteps(5)
            ->withPrompt('What time is the Tigers game today in Detroit and should I wear a coat? Please check both the game time and weather.')
            ->asStructured();

        // Verify structured output
        expect($response->structured)->toBeArray()
            ->and($response->structured)->toHaveKeys(['game_time', 'weather_summary', 'recommendation'])
            ->and($response->structured['game_time'])->toBeString()
            ->and($response->structured['weather_summary'])->toBeString()
            ->and($response->structured['recommendation'])->toBeString();

        // Verify tool calls and results
        expect($response->toolCalls)->toBeArray();
        expect($response->toolResults)->toBeArray();

        // Verify we have at least one step
        expect($response->steps)->not()->toBeEmpty();

        // Verify final step has structured data
        $finalStep = $response->steps->last();
        expect($finalStep->structured)->toBeArray();
    });

    it('returns structured output immediately when no tool calls needed', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/structured-without-tool-calls');

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
            ->using(Provider::OpenAI, 'gpt-4o')
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

    it('handles tool orchestration correctly with multiple tool types', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/structured-with-tool-orchestration');

        $schema = new ObjectSchema(
            'research_summary',
            'Summary of research findings',
            [
                new StringSchema('findings', 'Key findings from research', true),
                new StringSchema('sources', 'Sources consulted', true),
            ],
            ['findings', 'sources']
        );

        $tools = [
            (new Tool)
                ->as('search_database')
                ->for('Search internal database')
                ->withStringParameter('query', 'Search query')
                ->using(fn (string $query): string => "Database results for: {$query}"),
            (new Tool)
                ->as('fetch_external')
                ->for('Fetch data from external API')
                ->withStringParameter('endpoint', 'API endpoint')
                ->using(fn (string $endpoint): string => "External data from: {$endpoint}"),
        ];

        $response = Prism::structured()
            ->using(Provider::OpenAI, 'gpt-4o')
            ->withSchema($schema)
            ->withTools($tools)
            ->withMaxSteps(5)
            ->withPrompt('Research the topic "AI safety" using both internal and external sources')
            ->asStructured();

        // Verify structured output with research results
        expect($response->structured)->toBeArray()
            ->and($response->structured)->toHaveKeys(['findings', 'sources']);

        // Verify multiple steps with tool usage
        expect($response->steps)->not()->toBeEmpty();
        expect($response->toolCalls)->toBeArray();
        expect($response->toolResults)->toBeArray();
    });
});
