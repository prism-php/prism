<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Anthropic\Handlers\Structured;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'test-api-key'));
});

describe('Structured output with tools for Anthropic', function (): void {
    it('can generate structured output with a single tool using tool calling mode', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'anthropic/structured-with-single-tool');

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
            ->using(Provider::Anthropic, 'claude-sonnet-4-0')
            ->withSchema($schema)
            ->withTools([$weatherTool])
            ->withMaxSteps(3)
            ->withProviderOptions(['use_tool_calling' => true])
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
        FixtureResponse::fakeResponseSequence('*', 'anthropic/structured-with-multiple-tools');

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
            ->using(Provider::Anthropic, 'claude-sonnet-4-0')
            ->withSchema($schema)
            ->withTools($tools)
            ->withMaxSteps(5)
            ->withProviderOptions(['use_tool_calling' => true])
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

    it('can use JSON mode strategy with custom tools', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'anthropic/structured-with-tools-json-mode');

        $schema = new ObjectSchema(
            'simple_analysis',
            'Simple analysis result',
            [
                new StringSchema('result', 'The result', true),
            ],
            ['result']
        );

        $searchTool = (new Tool)
            ->as('search_web')
            ->for('Search the web for information')
            ->withStringParameter('query', 'The search query')
            ->using(fn (string $query): string => "Search results for: {$query}");

        $response = Prism::structured()
            ->using(Provider::Anthropic, 'claude-sonnet-4-0')
            ->withSchema($schema)
            ->withTools([$searchTool])
            ->withMaxSteps(3)
            ->withProviderOptions(['use_tool_calling' => false])  // Use JSON mode instead
            ->withPrompt('Search for "Prism PHP" and give me a summary')
            ->asStructured();

        // Verify structured output
        expect($response->structured)->toBeArray()
            ->and($response->structured)->toHaveKey('result')
            ->and($response->structured['result'])->toBeString();

        // JSON mode should still support tools
        expect($response->toolCalls)->toBeArray();
        expect($response->toolResults)->toBeArray();
    });

    it('handles tool orchestration correctly with multiple tool types', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'anthropic/structured-with-tool-orchestration');

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
            ->using(Provider::Anthropic, 'claude-sonnet-4-0')
            ->withSchema($schema)
            ->withTools($tools)
            ->withMaxSteps(5)
            ->withProviderOptions(['use_tool_calling' => true])
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

    it('includes strict field in tool definition when specified', function (): void {
        Prism::fake();

        $schema = new ObjectSchema(
            'output',
            'the output object',
            [new StringSchema('result', 'The result', true)],
            ['result']
        );

        $strictTool = (new Tool)
            ->as('get_data')
            ->for('Get data from source')
            ->withStringParameter('query', 'The query')
            ->withProviderOptions(['strict' => true])
            ->using(fn (string $query): string => "Data for: {$query}");

        $request = Prism::structured()
            ->using(Provider::Anthropic, 'claude-sonnet-4-5-20250929')
            ->withSchema($schema)
            ->withTools([$strictTool])
            ->withProviderOptions(['use_tool_calling' => true]);

        $payload = Structured::buildHttpRequestPayload(
            $request->toRequest()
        );

        $customTools = array_filter(
            $payload['tools'],
            fn (array $tool): bool => $tool['name'] !== 'output_structured_data'
        );

        expect($customTools)->toHaveCount(1);
        $tool = reset($customTools);
        expect($tool)->toHaveKey('strict');
        expect($tool['strict'])->toBe(true);
    });

    it('respects withToolChoice when custom tools are present', function (): void {
        Prism::fake();

        $schema = new ObjectSchema(
            'output',
            'the output object',
            [new StringSchema('result', 'The result', true)],
            ['result']
        );

        $searchTool = (new Tool)
            ->as('search')
            ->for('Search for information')
            ->withStringParameter('query', 'The search query')
            ->using(fn (string $query): string => "Results for: {$query}");

        $request = Prism::structured()
            ->using(Provider::Anthropic, 'claude-sonnet-4-0')
            ->withSchema($schema)
            ->withTools([$searchTool])
            ->withToolChoice('search')
            ->withProviderOptions(['use_tool_calling' => true]);

        $payload = Structured::buildHttpRequestPayload(
            $request->toRequest()
        );

        expect($payload['tool_choice'])->toBe(['type' => 'tool', 'name' => 'search']);
    });

    it('uses ToolChoice::Any correctly with custom tools', function (): void {
        Prism::fake();

        $schema = new ObjectSchema(
            'output',
            'the output object',
            [new StringSchema('result', 'The result', true)],
            ['result']
        );

        $searchTool = (new Tool)
            ->as('search')
            ->for('Search for information')
            ->withStringParameter('query', 'The search query')
            ->using(fn (string $query): string => "Results for: {$query}");

        $request = Prism::structured()
            ->using(Provider::Anthropic, 'claude-sonnet-4-0')
            ->withSchema($schema)
            ->withTools([$searchTool])
            ->withToolChoice(ToolChoice::Any)
            ->withProviderOptions(['use_tool_calling' => true]);

        $payload = Structured::buildHttpRequestPayload(
            $request->toRequest()
        );

        expect($payload['tool_choice'])->toBe(['type' => 'any']);
    });

    it('defaults to auto tool_choice when custom tools present and no explicit choice', function (): void {
        Prism::fake();

        $schema = new ObjectSchema(
            'output',
            'the output object',
            [new StringSchema('result', 'The result', true)],
            ['result']
        );

        $searchTool = (new Tool)
            ->as('search')
            ->for('Search for information')
            ->withStringParameter('query', 'The search query')
            ->using(fn (string $query): string => "Results for: {$query}");

        $request = Prism::structured()
            ->using(Provider::Anthropic, 'claude-sonnet-4-0')
            ->withSchema($schema)
            ->withTools([$searchTool])
            ->withProviderOptions(['use_tool_calling' => true]);

        $payload = Structured::buildHttpRequestPayload(
            $request->toRequest()
        );

        expect($payload['tool_choice'])->toBe(['type' => 'auto']);
    });

    it('throws exception when ToolChoice::None is used with tool-based structured output', function (): void {
        Prism::fake();

        $schema = new ObjectSchema(
            'output',
            'the output object',
            [new StringSchema('result', 'The result', true)],
            ['result']
        );

        $searchTool = (new Tool)
            ->as('search')
            ->for('Search for information')
            ->withStringParameter('query', 'The search query')
            ->using(fn (string $query): string => "Results for: {$query}");

        $request = Prism::structured()
            ->using(Provider::Anthropic, 'claude-sonnet-4-0')
            ->withSchema($schema)
            ->withTools([$searchTool])
            ->withToolChoice(ToolChoice::None)
            ->withProviderOptions(['use_tool_calling' => true]);

        expect(fn (): array => Structured::buildHttpRequestPayload($request->toRequest()))
            ->toThrow(PrismException::class, 'ToolChoice::None is incompatible with tool-based structured output');
    });

    it('forces structured output tool when no custom tools and no explicit choice', function (): void {
        Prism::fake();

        $schema = new ObjectSchema(
            'output',
            'the output object',
            [new StringSchema('result', 'The result', true)],
            ['result']
        );

        $request = Prism::structured()
            ->using(Provider::Anthropic, 'claude-sonnet-4-0')
            ->withSchema($schema)
            ->withProviderOptions(['use_tool_calling' => true]);

        $payload = Structured::buildHttpRequestPayload(
            $request->toRequest()
        );

        expect($payload['tool_choice'])->toBe(['type' => 'tool', 'name' => 'output_structured_data']);
    });
});
