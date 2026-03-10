<?php

declare(strict_types=1);

namespace Tests\Providers\OpenRouter;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openrouter.api_key', env('OPENROUTER_API_KEY', 'test-api-key'));
});

describe('Structured output with tools for OpenRouter', function (): void {
    it('can generate structured output with a single tool', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/structured-with-single-tool');

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
            ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
            ->withSchema($schema)
            ->withTools([$weatherTool])
            ->withMaxSteps(3)
            ->withPrompt('What is the weather in San Francisco and should I wear a coat?')
            ->asStructured();

        expect($response->structured)->toBeArray()
            ->and($response->structured)->toHaveKeys(['summary', 'recommendation'])
            ->and($response->structured['summary'])->toBeString()
            ->and($response->structured['recommendation'])->toBeString();

        expect($response->toolCalls)->toBeArray();
        expect($response->toolResults)->toBeArray();

        $finalStep = $response->steps->last();
        expect($finalStep->finishReason)->toBeIn([FinishReason::Stop, FinishReason::ToolCalls]);
    });

    it('can generate structured output with multiple tools and multi-step execution', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/structured-with-multiple-tools');

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
            ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
            ->withSchema($schema)
            ->withTools($tools)
            ->withMaxSteps(5)
            ->withPrompt('What time is the Tigers game today in Detroit and should I wear a coat? Please check both the game time and weather.')
            ->asStructured();

        expect($response->structured)->toBeArray()
            ->and($response->structured)->toHaveKeys(['game_time', 'weather_summary', 'recommendation'])
            ->and($response->structured['game_time'])->toBeString()
            ->and($response->structured['weather_summary'])->toBeString()
            ->and($response->structured['recommendation'])->toBeString();

        expect($response->toolCalls)->toBeArray();
        expect($response->toolResults)->toBeArray();

        expect($response->steps)->not()->toBeEmpty();

        $finalStep = $response->steps->last();
        expect($finalStep->structured)->toBeArray();
    });

    it('returns structured output immediately when no tool calls needed', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/structured-without-tool-calls');

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
            ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
            ->withSchema($schema)
            ->withTools([$weatherTool])
            ->withPrompt('What is 2 + 2?')
            ->asStructured();

        expect($response->structured)->toBeArray()
            ->and($response->structured)->toHaveKey('answer')
            ->and($response->structured['answer'])->toBeString();

        expect($response->toolCalls)->toBeArray();

        expect($response->steps)->toHaveCount(1);
    });

    it('stops execution when client-executed tool is called', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/structured-with-client-executed-tool');

        $schema = new ObjectSchema(
            'output',
            'the output object',
            [new StringSchema('result', 'The result', true)],
            ['result']
        );

        $tool = (new Tool)
            ->as('client_tool')
            ->for('A tool that executes on the client')
            ->withStringParameter('input', 'Input parameter');

        $response = Prism::structured()
            ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
            ->withSchema($schema)
            ->withTools([$tool])
            ->withMaxSteps(3)
            ->withPrompt('Use the client tool')
            ->asStructured();

        expect($response->finishReason)->toBe(FinishReason::ToolCalls);
        expect($response->toolCalls)->toHaveCount(1);
        expect($response->toolCalls[0]->name)->toBe('client_tool');
        expect($response->steps)->toHaveCount(1);
    });

    it('stops execution when approval-required tool is called', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/structured-with-approval-tool');

        $schema = new ObjectSchema(
            'output',
            'the output object',
            [new StringSchema('result', 'The result', true)],
            ['result']
        );

        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file. Requires user approval.')
            ->withStringParameter('path', 'File path to delete')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $response = Prism::structured()
            ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
            ->withSchema($schema)
            ->withTools([$tool])
            ->withMaxSteps(3)
            ->withPrompt('Delete /tmp/test.txt')
            ->asStructured();

        expect($response->finishReason)->toBe(FinishReason::ToolCalls);
        expect($response->toolCalls)->toHaveCount(1);
        expect($response->toolCalls[0]->name)->toBe('delete_file');
        expect($response->steps)->toHaveCount(1);
    });

    it('executes approved tool and returns structured output (Phase 2)', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/structured-with-approval-phase2');

        $schema = new ObjectSchema(
            'output',
            'the output object',
            [new StringSchema('result', 'The result', true)],
            ['result']
        );

        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file. Requires user approval.')
            ->withStringParameter('path', 'File path to delete')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $response = Prism::structured()
            ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
            ->withSchema($schema)
            ->withTools([$tool])
            ->withMaxSteps(3)
            ->withMessages([
                new UserMessage('Delete /tmp/test.txt'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call_delete_file', name: 'delete_file', arguments: ['path' => '/tmp/test.txt']),
                    ],
                ),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'call_delete_file', approved: true),
                ]),
            ])
            ->asStructured();

        expect($response->structured)->toBeArray()
            ->and($response->structured)->toHaveKey('result')
            ->and($response->structured['result'])->toContain('deleted');
        expect($response->finishReason)->toBe(FinishReason::Stop);
    });

    it('handles tool orchestration correctly with multiple tool types', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'openrouter/structured-with-tool-orchestration');

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
            ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
            ->withSchema($schema)
            ->withTools($tools)
            ->withMaxSteps(5)
            ->withPrompt('Research the topic "AI safety" using both internal and external sources')
            ->asStructured();

        expect($response->structured)->toBeArray()
            ->and($response->structured)->toHaveKeys(['findings', 'sources']);

        expect($response->steps)->not()->toBeEmpty();
        expect($response->toolCalls)->toBeArray();
        expect($response->toolResults)->toBeArray();
    });
});
