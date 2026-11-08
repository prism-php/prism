<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Prism\Prism\Providers\OpenAI\Maps\ToolMap;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;

it('maps tools', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Searching the web')
        ->withStringParameter('query', 'the detailed search query')
        ->using(fn (): string => '[Search results]');

    expect(ToolMap::map([$tool]))->toBe([[
        'type' => 'function',
        'name' => $tool->name(),
        'description' => $tool->description(),
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'description' => 'the detailed search query',
                    'type' => 'string',
                ],
            ],
            'required' => $tool->requiredParameters(),
        ],
    ]]);
});

it('maps tools with strict mode', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Searching the web')
        ->withStringParameter('query', 'the detailed search query')
        ->using(fn (): string => '[Search results]')
        ->withProviderOptions([
            'strict' => true,
        ]);

    expect(ToolMap::map([$tool]))->toBe([[
        'type' => 'function',
        'name' => $tool->name(),
        'description' => $tool->description(),
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'description' => 'the detailed search query',
                    'type' => 'string',
                ],
            ],
            'required' => $tool->requiredParameters(),
            'additionalProperties' => false,
        ],
        'strict' => true,
    ]]);
});

it('disallows additional properties on every object level in strict mode', function (): void {
    $tool = (new Tool)
        ->as('get_weather')
        ->for('Get the weather for a location')
        ->withObjectParameter('location', 'The location to look up', [
            new StringSchema('city', 'The city name'),
            new StringSchema('country', 'The country code'),
        ], ['city', 'country'])
        ->using(fn (): string => 'Sunny')
        ->withProviderOptions([
            'strict' => true,
        ]);

    expect(ToolMap::map([$tool]))->toBe([[
        'type' => 'function',
        'name' => 'get_weather',
        'description' => 'Get the weather for a location',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'location' => [
                    'description' => 'The location to look up',
                    'type' => 'object',
                    'properties' => [
                        'city' => [
                            'description' => 'The city name',
                            'type' => 'string',
                        ],
                        'country' => [
                            'description' => 'The country code',
                            'type' => 'string',
                        ],
                    ],
                    'required' => ['city', 'country'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['location'],
            'additionalProperties' => false,
        ],
        'strict' => true,
    ]]);
});
