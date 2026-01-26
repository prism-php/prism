<?php

declare(strict_types=1);

namespace Tests;

use Prism\Prism\Facades\Tool as ToolFacade;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;

it('can set tool as concurrent', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('useful for searching')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(fn (string $query): string => "Result for $query")
        ->concurrent();

    expect($tool->isConcurrent())->toBeTrue();
});

it('tools are not concurrent by default', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('useful for searching')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(fn (string $query): string => "Result for $query");

    expect($tool->isConcurrent())->toBeFalse();
});

it('can explicitly set tool as non-concurrent', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('useful for searching')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(fn (string $query): string => "Result for $query")
        ->concurrent(false);

    expect($tool->isConcurrent())->toBeFalse();
});

it('can use concurrent via facade', function (): void {
    $tool = ToolFacade::as('search')
        ->for('useful for searching')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(fn (string $query): string => "Result for $query")
        ->concurrent();

    expect($tool->isConcurrent())->toBeTrue();
});

it('concurrent method can be chained with other methods', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('useful for searching')
        ->concurrent()
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(fn (string $query): string => "Result for $query");

    expect($tool->isConcurrent())->toBeTrue();
    expect($tool->name())->toBe('search');
    expect($tool->description())->toBe('useful for searching');
});

it('handles errors in concurrent tools gracefully', function (): void {
    $tool = (new Tool)
        ->as('error_tool')
        ->for('tool that throws error')
        ->withParameter(new StringSchema('query', 'the search query'))
        ->using(function (string $query): string {
            throw new \RuntimeException('Tool execution failed');
        })
        ->concurrent();

    $result = $tool->handle('test');

    expect($result)
        ->toContain('Tool execution error')
        ->toContain('Tool execution failed');
});
