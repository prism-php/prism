<?php

declare(strict_types=1);

namespace Tests;

use ArgumentCountError;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Tool;
use TypeError;

it('throws exception when no error handler is set', function (): void {
    $tool = (new Tool)
        ->as('calculate')
        ->for('Perform calculation')
        ->withNumberParameter('a', 'First number')
        ->withNumberParameter('b', 'Second number')
        ->using(fn(int $a, int $b): string => (string) ($a + $b));

    expect(fn (): string => $tool->handle('five', 10))
        ->toThrow(PrismException::class, 'Invalid parameters for tool : calculate');
});

it('uses custom failed handler when provided', function (): void {
    $tool = (new Tool)
        ->as('calculate')
        ->for('Perform calculation')
        ->withNumberParameter('a', 'First number')
        ->withNumberParameter('b', 'Second number')
        ->using(fn(int $a, int $b): string => (string) ($a + $b))
        ->failed(fn(\Throwable $e, array $params): string => 'Custom error: Parameters must be numbers');

    $result = $tool->handle('five', 10);

    expect($result)->toBe('Custom error: Parameters must be numbers');
});

it('uses default error handler with handleErrors()', function (): void {
    $tool = (new Tool)
        ->as('calculate')
        ->for('Perform calculation')
        ->withNumberParameter('a', 'First number')
        ->withNumberParameter('b', 'Second number')
        ->using(fn(int $a, int $b): string => (string) ($a + $b))
        ->handleErrors();

    $result = $tool->handle('five', 10);

    expect($result)
        ->toContain('Type mismatch in parameters')
        ->toContain('Expected: [a (NumberSchema, required), b (NumberSchema, required)]')
        ->toContain('Received: {"a":"five","b":10}');
});

it('handles missing required parameters gracefully', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Search files')
        ->withStringParameter('query', 'Search query')
        ->withStringParameter('path', 'Directory path')
        ->using(fn(string $query, string $path): string => "Found results for $query in $path")
        ->handleErrors();

    // Missing second parameter
    $result = $tool->handle('test query');

    expect($result)
        ->toContain('Missing required parameters')
        ->toContain('Expected: [query (StringSchema, required), path (StringSchema, required)]');
});

it('handles unknown parameters gracefully', function (): void {
    $tool = (new Tool)
        ->as('simple')
        ->for('Simple tool')
        ->withStringParameter('name', 'Name')
        ->using(fn(string $name): string => "Hello $name")
        ->handleErrors();

    // Unknown parameter 'unknown'
    $result = $tool->handle(name: 'John', unknown: 'parameter');

    expect($result)
        ->toContain('Unknown parameters provided')
        ->toContain('Expected: [name (StringSchema, required)]');
});

it('handles optional parameters correctly', function (): void {
    $tool = (new Tool)
        ->as('read_file')
        ->for('Read file')
        ->withStringParameter('path', 'File path')
        ->withNumberParameter('lines', 'Number of lines', required: false)
        ->using(fn(string $path, ?int $lines = null): string => "Reading $path".($lines ? " ($lines lines)" : ''))
        ->handleErrors();

    // Valid call with optional parameter as wrong type
    $result = $tool->handle('/path/to/file', 'ten');

    expect($result)
        ->toContain('Type mismatch in parameters')
        ->toContain('lines (NumberSchema)'); // Note: not marked as required
});

it('returns successful result when parameters are valid', function (): void {
    $tool = (new Tool)
        ->as('calculate')
        ->for('Perform calculation')
        ->withNumberParameter('a', 'First number')
        ->withNumberParameter('b', 'Second number')
        ->using(fn(int $a, int $b): string => (string) ($a + $b))
        ->handleErrors();

    $result = $tool->handle(5, 10);

    expect($result)->toBe('15');
});

it('allows custom error messages based on exception type', function (): void {
    $tool = (new Tool)
        ->as('api_call')
        ->for('Make API call')
        ->withStringParameter('endpoint', 'API endpoint')
        ->withArrayParameter('data', 'Request data', new \Prism\Prism\Schema\StringSchema('item', 'Data item'))
        ->using(fn(string $endpoint, array $data): string => "Called $endpoint")
        ->failed(function (\Throwable $e, array $params): string {
            if ($e instanceof TypeError && str_contains($e->getMessage(), 'array')) {
                return "The 'data' parameter must be an array, not a string. Example: ['item1', 'item2']";
            }
            if ($e instanceof ArgumentCountError) {
                return "Missing parameters. Both 'endpoint' and 'data' are required.";
            }

            return "API call failed: {$e->getMessage()}";
        });

    // Test with wrong type
    $result1 = $tool->handle('/api/users', 'not-an-array');
    expect($result1)->toBe("The 'data' parameter must be an array, not a string. Example: ['item1', 'item2']");

    // Test with missing parameter
    $result2 = $tool->handle('/api/users');
    expect($result2)->toBe("Missing parameters. Both 'endpoint' and 'data' are required.");
});
