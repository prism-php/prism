<?php

declare(strict_types=1);

namespace Tests\Providers\Z;

use Prism\Prism\Providers\Z\Maps\ToolMap;
use Prism\Prism\Tool;

it('maps tools', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Searching the web')
        ->withStringParameter('query', 'the detailed search query')
        ->using(fn (): string => '[Search results]');

    expect(ToolMap::map([$tool]))->toBe([[
        'type' => 'function',
        'function' => [
            'name' => 'search',
            'description' => 'Searching the web',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'description' => 'the detailed search query',
                        'type' => 'string',
                    ],
                ],
                'required' => ['query'],
            ],
        ],
    ]]);
});

it('maps tools with no parameters with empty properties as json object', function (): void {
    $tool = (new Tool)
        ->as('get_schema')
        ->for('Returns the current schema')
        ->using(fn (): string => '[Schema]');

    expect(ToolMap::map([$tool]))->toEqual([[
        'type' => 'function',
        'function' => [
            'name' => 'get_schema',
            'description' => 'Returns the current schema',
            'parameters' => [
                'type' => 'object',
                'properties' => (object) [],
                'required' => [],
            ],
        ],
    ]]);
});
