<?php

declare(strict_types=1);

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism as PrismFacade;
use Prism\Prism\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;

beforeEach(function (): void {
    Prism::handleToolErrorsUsing(null);
});

afterEach(function (): void {
    Prism::handleToolErrorsUsing(null);
});

it('keeps legacy failure bytes until a handler is registered and restores them after reset', function (): void {
    $tool = (new Tool)->as('probe')->using(fn () => throw new RuntimeException('synthetic-private-host'));
    $legacy = 'Tool execution error: synthetic-private-host. This error occurred during tool execution, not due to invalid parameters.';
    expect($tool->handle()->message)->toBe($legacy);
    PrismFacade::handleToolErrorsUsing(fn (): string => 'Service unavailable.');
    expect($tool->handle()->message)->toBe('Service unavailable.');
    Prism::handleToolErrorsUsing(null);
    expect($tool->handle()->message)->toBe($legacy);
});

it('passes the original exception tool and supplied parameters to the current application handler', function (bool $named): void {
    $error = new RuntimeException('synthetic-private-host');
    $tool = (new Tool)->as('probe')->withStringParameter('query', 'Query')->using(fn (string $query) => throw $error);
    $seen = [];
    Prism::handleToolErrorsUsing(function (Throwable $actual, Tool $actualTool, array $params) use (&$seen): string {
        $seen[] = [$actual, $actualTool, $params];

        return 'Safe retry message.';
    });
    $result = $named ? $tool->handle(query: 'synthetic-private-input') : $tool->handle('synthetic-private-input');
    expect($result)->toBeInstanceOf(ToolError::class)->and($result->message)->toBe('Safe retry message.')
        ->and($seen)->toBe([[$error, $tool, ['query' => 'synthetic-private-input']]]);
    Prism::handleToolErrorsUsing(fn (): string => 'Replacement policy.');
    expect($tool->handle('next')->message)->toBe('Replacement policy.');
})->with([true, false]);

it('keeps an explicit failed handler ahead of the application handler and restores the application default', function (): void {
    $calls = 0;
    Prism::handleToolErrorsUsing(function () use (&$calls): string {
        $calls++;

        return 'Application default.';
    });
    $error = new RuntimeException('synthetic-private-host');
    $tool = (new Tool)->as('probe')->withStringParameter('query', 'Query')->using(fn (string $query) => throw $error)
        ->failed(function (Throwable $actual, array $params) use ($error): string {
            expect($actual)->toBe($error)->and($params)->toBe(['query' => 'value']);

            return 'Tool-specific message.';
        });
    expect($tool->handle(query: 'value')->message)->toBe('Tool-specific message.')->and($calls)->toBe(0);
    $tool->withErrorHandling();
    expect($tool->handle(query: 'value')->message)->toBe('Application default.')->and($calls)->toBe(1);
});

it('fails closed when the application handler throws or returns a non-string', function (string $scenario): void {
    $secret = 'synthetic-billing-secret';
    $handler = match ($scenario) {
        'throws' => fn () => throw new LogicException($secret),
        'typed' => fn (): string => [],
        'null' => fn (): null => null,
        'array' => fn (): array => ['secret' => $secret],
        'int' => fn (): int => 17,
        'bool' => fn (): bool => false,
        'object' => fn (): object => new class
        {
            public function __toString(): string
            {
                return 'synthetic-coercion-secret';
            }
        },
    };
    Prism::handleToolErrorsUsing($handler);
    $tool = (new Tool)->as('probe')->withStringParameter('query', 'Query')->using(fn (string $query) => throw new RuntimeException($secret));
    $result = $tool->handle(query: 'synthetic-private-input');
    expect($result)->toBeInstanceOf(ToolError::class)->and($result->message)->toBe('The tool failed.');
})->with(['throws', 'typed', 'null', 'array', 'int', 'bool', 'object']);

it('accepts a deliberately empty string from the application handler', function (): void {
    Prism::handleToolErrorsUsing(fn (): string => '');
    $tool = (new Tool)->as('probe')->using(fn () => throw new RuntimeException('synthetic-private-host'));
    expect($tool->handle()->message)->toBe('');
});

it('redacts validation failures before legacy parameter formatting', function (): void {
    $seen = [];
    Prism::handleToolErrorsUsing(function (Throwable $error, Tool $tool, array $params) use (&$seen): string {
        $seen[] = [$error instanceof TypeError, $tool->name(), $params];

        return 'Invalid input.';
    });
    $tool = (new Tool)->as('probe')->withNumberParameter('count', 'Count')->using(fn (int $count): string => (string) $count);
    expect($tool->handle(count: 'synthetic-private-input')->message)->toBe('Invalid input.')
        ->and($seen)->toBe([[true, 'probe', ['count' => 'synthetic-private-input']]]);
});

it('preserves explicit native exception opt-outs with an application handler installed', function (): void {
    Prism::handleToolErrorsUsing(fn () => throw new LogicException('Application hook must not run.'));
    $error = new RuntimeException('synthetic-native-secret');
    $tool = (new Tool)->as('probe')->withoutErrorHandling()->using(fn () => throw $error);
    try {
        $tool->handle();
        test()->fail('Expected the native exception.');
    } catch (RuntimeException $actual) {
        expect($actual)->toBe($error);
    }
    $tool->using(fn (int $count): string => (string) $count);
    expect(fn (): string|ToolOutput|ToolError => $tool->handle('invalid'))->toThrow(PrismException::class, 'Invalid parameters for tool : probe');
});

it('does not rewrite returned failure strings or successful tool output', function (): void {
    $calls = 0;
    Prism::handleToolErrorsUsing(function () use (&$calls): string {
        $calls++;

        return 'Must not replace returned results.';
    });
    $body = '{"success":false,"error":"consumer-owned"}';
    $tool = (new Tool)->as('probe')->using(fn (): string => $body);
    expect($tool->handle())->toBe($body);
    $output = new ToolOutput('success');
    $tool->using(fn (): ToolOutput => $output);
    expect($tool->handle())->toBe($output)->and($calls)->toBe(0);
});
