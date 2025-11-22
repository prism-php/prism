<?php

declare(strict_types=1);

namespace Tests;

use ArgumentCountError;
use DivisionByZeroError;
use InvalidArgumentException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use RuntimeException;
use Throwable;
use TypeError;

it('returns error message by default when invalid parameters are provided', function (): void {
    $tool = (new Tool)
        ->as('calculate')
        ->for('Perform calculation')
        ->withNumberParameter('a', 'First number')
        ->withNumberParameter('b', 'Second number')
        ->using(fn (int $a, int $b): string => (string) ($a + $b));

    $result = $tool->handle('five', 10);

    expect($result)
        ->toContain('Parameter validation error: Type mismatch')
        ->toContain('Expected: [a (NumberSchema, required), b (NumberSchema, required)]')
        ->toContain('Received: {"a":"five","b":10}');
});

it('throws exception when error handler is explicitly disabled', function (): void {
    $tool = (new Tool)
        ->as('calculate')
        ->for('Perform calculation')
        ->withNumberParameter('a', 'First number')
        ->withNumberParameter('b', 'Second number')
        ->using(fn (int $a, int $b): string => (string) ($a + $b))
        ->withoutErrorHandling();

    expect(fn (): string => $tool->handle('five', 10))
        ->toThrow(PrismException::class, 'Invalid parameters for tool : calculate');
});

it('uses custom failed handler when provided', function (): void {
    $tool = (new Tool)
        ->as('calculate')
        ->for('Perform calculation')
        ->withNumberParameter('a', 'First number')
        ->withNumberParameter('b', 'Second number')
        ->using(fn (int $a, int $b): string => (string) ($a + $b))
        ->failed(fn (Throwable $e, array $params): string => 'Custom error: Parameters must be numbers');

    $result = $tool->handle('five', 10);

    expect($result)->toBe('Custom error: Parameters must be numbers');
});

it('uses default error handler with handleToolErrors()', function (): void {
    $tool = (new Tool)
        ->as('calculate')
        ->for('Perform calculation')
        ->withNumberParameter('a', 'First number')
        ->withNumberParameter('b', 'Second number')
        ->using(fn (int $a, int $b): string => (string) ($a + $b));

    $result = $tool->handle('five', 10);

    expect($result)
        ->toContain('Parameter validation error: Type mismatch')
        ->toContain('Expected: [a (NumberSchema, required), b (NumberSchema, required)]')
        ->toContain('Received: {"a":"five","b":10}');
});

it('handles missing required parameters gracefully', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Search files')
        ->withStringParameter('query', 'Search query')
        ->withStringParameter('path', 'Directory path')
        ->using(fn (string $query, string $path): string => "Found results for $query in $path");

    // Missing second parameter
    $result = $tool->handle('test query');

    expect($result)
        ->toContain('Parameter validation error: Missing required parameters')
        ->toContain('Expected: [query (StringSchema, required), path (StringSchema, required)]');
});

it('handles unknown parameters gracefully', function (): void {
    $tool = (new Tool)
        ->as('simple')
        ->for('Simple tool')
        ->withStringParameter('name', 'Name')
        ->using(fn (string $name): string => "Hello $name");

    // Unknown parameter 'unknown'
    $result = $tool->handle(name: 'John', unknown: 'parameter');

    expect($result)
        ->toContain('Parameter validation error: Unknown parameters')
        ->toContain('Expected: [name (StringSchema, required)]');
});

it('handles optional parameters correctly', function (): void {
    $tool = (new Tool)
        ->as('read_file')
        ->for('Read file')
        ->withStringParameter('path', 'File path')
        ->withNumberParameter('lines', 'Number of lines', required: false)
        ->using(fn (string $path, ?int $lines = null): string => "Reading $path".($lines ? " ($lines lines)" : ''));

    // Valid call with optional parameter as wrong type
    $result = $tool->handle('/path/to/file', 'ten');

    expect($result)
        ->toContain('Parameter validation error: Type mismatch')
        ->toContain('lines (NumberSchema)'); // Note: not marked as required
});

it('returns successful result when parameters are valid', function (): void {
    $tool = (new Tool)
        ->as('calculate')
        ->for('Perform calculation')
        ->withNumberParameter('a', 'First number')
        ->withNumberParameter('b', 'Second number')
        ->using(fn (int $a, int $b): string => (string) ($a + $b));

    $result = $tool->handle(5, 10);

    expect($result)->toBe('15');
});

it('allows custom error messages based on exception type', function (): void {
    $tool = (new Tool)
        ->as('api_call')
        ->for('Make API call')
        ->withStringParameter('endpoint', 'API endpoint')
        ->withArrayParameter('data', 'Request data', new StringSchema('item', 'Data item'))
        ->using(fn (string $endpoint, array $data): string => "Called $endpoint")
        ->failed(function (Throwable $e, array $params): string {
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

it('differentiates between validation errors and runtime errors', function (): void {
    $tool = (new Tool)
        ->as('file_reader')
        ->for('Read a file')
        ->withStringParameter('path', 'File path')
        ->using(function (string $path): string {
            if (! file_exists($path)) {
                throw new RuntimeException("File not found: $path");
            }

            return file_get_contents($path);
        });

    // Test validation error (wrong type)
    $result1 = $tool->handle(['not', 'a', 'string']);
    expect($result1)
        ->toContain('Parameter validation error: Type mismatch')
        ->toContain('Expected: [path (StringSchema, required)]');

    // Test runtime error (file doesn't exist)
    $result2 = $tool->handle('/nonexistent/file.txt');
    expect($result2)
        ->toContain('Tool execution error: File not found: /nonexistent/file.txt')
        ->toContain('This error occurred during tool execution, not due to invalid parameters');
});

// Note: Schema validation for complex types (objects, arrays with specific item types, enums)
// is not currently implemented. The Tool class relies on PHP's type system.
// These tests demonstrate the desired behavior for future enhancement.

it('handles complex parameter types with current implementation', function (): void {
    $tool = (new Tool)
        ->as('create_user')
        ->for('Create a new user')
        ->withObjectParameter('user', 'User information', [
            new StringSchema('name', 'User name'),
            new NumberSchema('age', 'User age'),
            new StringSchema('email', 'User email'),
        ], ['name', 'age', 'email'])
        ->using(fn (array $user): string => "Created user: {$user['name']}");

    // Currently passes through without schema validation
    $result = $tool->handle(['name' => 'John']);
    expect($result)->toBe('Created user: John');
});

it('handles array parameters that cause runtime errors', function (): void {
    $tool = (new Tool)
        ->as('divide_numbers')
        ->for('Divide first number by second')
        ->withArrayParameter('numbers', 'Two numbers: dividend and divisor', new NumberSchema('number', 'A number'))
        ->using(function (array $numbers): string {
            if (count($numbers) !== 2) {
                throw new InvalidArgumentException('Exactly 2 numbers required');
            }
            if ($numbers[1] == 0) {
                throw new DivisionByZeroError('Cannot divide by zero');
            }

            return 'Result: '.($numbers[0] / $numbers[1]);
        });

    // Test with division by zero - a real runtime error
    $result = $tool->handle([10, 0]);
    expect($result)
        ->toContain('Tool execution error')
        ->toContain('Cannot divide by zero');
});

it('demonstrates enum parameters without validation', function (): void {
    $tool = (new Tool)
        ->as('set_status')
        ->for('Set status')
        ->withEnumParameter('status', 'Status value', ['active', 'inactive', 'pending'])
        ->using(fn (string $status): string => "Status set to: $status");

    // Currently accepts any string value
    $result = $tool->handle('completed');
    expect($result)->toBe('Status set to: completed');
});

it('handles boolean parameters with PHP type coercion', function (): void {
    $tool = (new Tool)
        ->as('toggle_feature')
        ->for('Toggle a feature')
        ->withBooleanParameter('enabled', 'Whether to enable the feature')
        ->using(fn (bool $enabled): string => $enabled ? 'Feature enabled' : 'Feature disabled');

    // PHP coerces 'yes' to true
    $result = $tool->handle('yes');
    expect($result)->toBe('Feature enabled');

    // Test with actual type error
    $result2 = $tool->handle(['array']);
    expect($result2)
        ->toContain('Parameter validation error: Type mismatch')
        ->toContain('enabled (BooleanSchema, required)');
});

it('handles multiple optional parameters with partial invalid data', function (): void {
    $tool = (new Tool)
        ->as('search_files')
        ->for('Search for files')
        ->withStringParameter('query', 'Search query')
        ->withStringParameter('path', 'Directory path', required: false)
        ->withNumberParameter('limit', 'Max results', required: false)
        ->withBooleanParameter('recursive', 'Search recursively', required: false)
        ->using(fn (string $query, ?string $path = './', ?int $limit = 10, ?bool $recursive = false): string => "Searching for '$query' in $path (limit: $limit, recursive: ".($recursive ? 'yes' : 'no').')');

    // Test with some valid and some invalid optional parameters
    $result = $tool->handle('test', null, 'not-a-number', 'not-a-boolean');
    expect($result)
        ->toContain('Parameter validation error: Type mismatch')
        ->toContain('limit (NumberSchema)')
        ->toContain('recursive (BooleanSchema)');
});
