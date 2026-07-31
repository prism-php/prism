<?php

declare(strict_types=1);

namespace Tests;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Tool as ToolFacade;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;

enum ToolTestStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

it('can return tool details', function (): void {
    $searchTool = (new Tool)
        ->as('search')
        ->for('useful for searching current data')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(function (string $query): string {
            expect($query)->toBe('What time is the event?');

            return 'The event is at 3pm eastern';
        });

    expect($searchTool->name())->toBe('search');
    expect($searchTool->description())->toBe('useful for searching current data');
    expect($searchTool->parametersAsArray())->toBe([
        'query' => [
            'description' => 'the search query',
            'type' => 'string',
        ],
    ]);

    expect($searchTool->requiredParameters())->toBe(['query']);
});

it('can use a closure', function (): void {
    $searchTool = (new Tool)
        ->as('search')
        ->for('useful for searching current data')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(function (string $query): string {
            expect($query)->toBe('What time is the event?');

            return 'The event is at 3pm eastern';

        });

    expect($searchTool->handle('What time is the event?'))
        ->toBe('The event is at 3pm eastern');
});

it('converts backed enum arguments before invoking the tool', function (): void {
    $tool = (new Tool)
        ->as('set_status')
        ->for('Set a status')
        ->withEnumParameter('status', 'The status', ['active', 'inactive'])
        ->using(function (ToolTestStatus $status): string {
            return $status->name;
        });

    expect($tool->handle('active'))->toBe('Active')
        ->and($tool->handle(status: 'inactive'))->toBe('Inactive');
});

it('returns a validation error when a backed enum value is invalid', function (): void {
    $tool = (new Tool)
        ->as('set_status')
        ->for('Set a status')
        ->withEnumParameter('status', 'The status', ['active', 'inactive'])
        ->using(fn (ToolTestStatus $status): string => $status->name);

    $result = $tool->handle('unknown');

    expect($result)->toBeInstanceOf(ToolError::class)
        ->and($result->message)->toContain('Parameter validation error');
});

it('can be used via facade', function (): void {
    $searchTool = ToolFacade::as('search')
        ->for('useful for searching current data')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(function (string $query): string {
            expect($query)->toBe('What time is the event?');

            return 'The event is at 3pm eastern';

        });

    expect($searchTool->handle('What time is the event?'))
        ->toBe('The event is at 3pm eastern');
});

it('can use an invokeable', function (): void {
    $fn = new class
    {
        public function __invoke(string $query): string
        {
            expect($query)->toBe('What time is the event?');

            return 'The event is at 3pm eastern';
        }
    };

    $searchTool = (new Tool)
        ->as('search')
        ->for('useful for searching current data')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using($fn);

    expect($searchTool->handle('What time is the event?'))
        ->toBe('The event is at 3pm eastern');
});

it('can have fluent parameters', function (): void {
    $tool = (new Tool)
        ->as('test tool')
        ->for('not really useful for anything')
        ->withStringParameter(name: 'query', description: 'the search query', required: false)
        ->withNumberParameter('age', 'the users age')
        ->withBooleanParameter('active', 'active status')
        ->withArrayParameter(
            name: 'items',
            description: 'user requested items',
            items: new StringSchema('itemm', 'an item that the user requested'),
        )
        ->withEnumParameter('status', 'the status', ['active', 'inactive'])
        ->withObjectParameter(
            name: 'user',
            description: 'the user object',
            properties: [
                new StringSchema('name', 'the users name'),
                new BooleanSchema('active_status', 'user active status'),
            ],
            requiredFields: [
                'name',
            ]
        );

    $keys = [
        'query',
        'age',
        'active',
        'items',
        'status',
        'user',
    ];

    expect($tool->parameters())->toHaveKeys($keys);

    collect($keys)->each(function ($key) use ($tool): void {
        expect($tool->parameters()[$key])->not->toBeEmpty();
    });

    expect($tool->requiredParameters())->not->toContain('query');
});

it('can throw a prism custom exception for invalid parameters', function (): void {
    $searchTool = (new Tool)
        ->as('search')
        ->for('useful for searching current data')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(function (string $query): string {
            expect($query)->toBe('What time is the event?');

            return 'The event is at 3pm eastern';
        })
        ->withoutErrorHandling(); // Disable error handling to get exception

    $this->expectException(PrismException::class);
    $this->expectExceptionMessage('Invalid parameters for tool : search');

    $searchTool->handle([]);
});

it('can throw a prism custom exception for unknown named parameters', function (): void {
    $searchTool = (new Tool)
        ->as('search')
        ->for('useful for searching current data')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(function (string $query): string {
            expect($query)->toBe('What time is the event?');

            return 'The event is at 3pm eastern';
        })
        ->withoutErrorHandling();

    $this->expectException(PrismException::class);
    $this->expectExceptionMessage('Invalid parameters for tool : search');

    $searchTool->handle(input: 'What time is the event?');
});

it('can throw a prism custom exception for invalid return type', function (): void {
    $searchTool = (new Tool)
        ->as('search')
        ->for('useful for searching current data')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(function (string $query): int {
            expect($query)->toBe('What time is the event?');

            return 1;
        })
        ->withoutErrorHandling();

    $this->expectException(PrismException::class);
    $this->expectExceptionMessage('Invalid return type for tool : search. Tools must return string.');

    $searchTool->handle('What time is the event?');
});
