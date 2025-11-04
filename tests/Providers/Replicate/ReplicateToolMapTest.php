<?php

declare(strict_types=1);

use Prism\Prism\Providers\Replicate\Maps\ToolMap;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;

it('maps a single tool to JSON schema', function (): void {
    $tool = (new Tool)->as('get_weather')
        ->for('Get current weather for a location')
        ->withStringParameter('location', 'The city name')
        ->using(fn (string $location): string => 'Sunny, 72°F');

    $json = ToolMap::map([$tool]);

    expect($json)->toBeJson();

    $decoded = json_decode($json, true);

    expect($decoded)->toHaveKey('tools')
        ->and($decoded['tools'])->toHaveCount(1)
        ->and($decoded['tools'][0])->toMatchArray([
            'name' => 'get_weather',
            'description' => 'Get current weather for a location',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'The city name',
                    ],
                ],
                'required' => ['location'],
            ],
        ]);
});

it('maps multiple tools correctly', function (): void {
    $tools = [
        (new Tool)->as('get_weather')
            ->for('Get weather')
            ->withStringParameter('city', 'City name')
            ->using(fn (): string => ''),
        (new Tool)->as('search')
            ->for('Search the web')
            ->withStringParameter('query', 'Search query')
            ->using(fn (): string => ''),
    ];

    $json = ToolMap::map($tools);
    $decoded = json_decode($json, true);

    expect($decoded['tools'])->toHaveCount(2)
        ->and($decoded['tools'][0]['name'])->toBe('get_weather')
        ->and($decoded['tools'][1]['name'])->toBe('search');
});

it('returns empty string for empty tools array', function (): void {
    expect(ToolMap::map([]))->toBe('');
});

it('builds system prompt with tool instructions', function (): void {
    $tools = [
        (new Tool)->as('test_tool')
            ->for('Test tool')
            ->withStringParameter('param', 'A parameter')
            ->using(fn (): string => ''),
    ];

    $prompt = ToolMap::buildSystemPrompt($tools);

    expect($prompt)->toContain('helpful assistant')
        ->and($prompt)->toContain('test_tool')
        ->and($prompt)->toContain('tool_calls')
        ->and($prompt)->toContain('JSON')
        ->and($prompt)->toContain('call_');
});

it('maps complex parameter types', function (): void {
    $tool = (new Tool)->as('complex_tool')
        ->for('Complex tool')
        ->withStringParameter('string_param', 'String')
        ->withNumberParameter('number_param', 'Number')
        ->withBooleanParameter('bool_param', 'Boolean')
        ->withArrayParameter('array_param', 'Array', new StringSchema('item', 'Item'))
        ->using(fn (): string => '');

    $json = ToolMap::map([$tool]);
    $decoded = json_decode($json, true);

    expect($decoded['tools'][0]['parameters']['properties'])
        ->toHaveKey('string_param')
        ->toHaveKey('number_param')
        ->toHaveKey('bool_param')
        ->toHaveKey('array_param');
});
