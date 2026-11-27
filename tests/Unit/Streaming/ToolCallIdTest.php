<?php

declare(strict_types=1);

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\ToolCallId;

it('retains the first non-empty provider id including the opaque string zero', function (): void {
    expect(ToolCallId::remember('first', 'later'))->toBe('first')
        ->and(ToolCallId::remember('0', 'later'))->toBe('0')
        ->and(ToolCallId::remember(null, '0'))->toBe('0')
        ->and(ToolCallId::remember('', 'later'))->toBe('later')
        ->and(ToolCallId::remember(null, ''))->toBeNull();
});

it('passes a provided id through without invoking or applying fallback rules', function (): void {
    $calls = 0;
    $generator = function () use (&$calls): string {
        $calls++;

        return 'ABC123XYZ';
    };
    expect(ToolCallId::resolve('provider-value', 'owned', $generator, '/\A[a-zA-Z0-9]{9}\z/D'))->toBe('provider-value')
        ->and($calls)->toBe(0);
});

it('generates once and validates the provider supplied fallback format', function (): void {
    $calls = 0;
    $generator = function () use (&$calls): string {
        $calls++;

        return 'ABC123XYZ';
    };
    expect(ToolCallId::resolve(null, 'owned', $generator, '/\A[a-zA-Z0-9]{9}\z/D'))->toBe('ABC123XYZ')
        ->and($calls)->toBe(1);
});

it('refuses an invalid generated fallback instead of emitting an unusable id', function (string $value): void {
    expect(fn (): string => ToolCallId::resolve(null, 'owned', fn (): string => $value, '/\A[a-zA-Z0-9]{9}\z/D'))
        ->toThrow(PrismException::class, 'The generated streaming tool-call ID does not match the declared format.');
})->with(['empty' => '', 'too short' => 'short', 'wrong charset' => 'bad_value', 'too long' => 'ABC123XYZextra']);
