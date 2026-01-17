<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\Contracts\SemanticMapperInterface;
use Prism\Prism\Telemetry\Semantics\PassthroughMapper;

it('implements SemanticMapperInterface', function (): void {
    $mapper = new PassthroughMapper;

    expect($mapper)->toBeInstanceOf(SemanticMapperInterface::class);
});

it('returns attributes unchanged', function (): void {
    $mapper = new PassthroughMapper;

    $attributes = [
        'model' => 'gpt-4',
        'provider' => 'openai',
        'temperature' => 0.7,
        'custom_field' => 'custom_value',
    ];

    $result = $mapper->map('text_generation', $attributes);

    expect($result)->toBe($attributes);
});

it('returns events unchanged', function (): void {
    $mapper = new PassthroughMapper;

    $events = [
        [
            'name' => 'test_event',
            'timeNanos' => 1000000000,
            'attributes' => ['key' => 'value'],
        ],
    ];

    $result = $mapper->mapEvents($events);

    expect($result)->toBe($events);
});

it('handles empty attributes', function (): void {
    $mapper = new PassthroughMapper;

    $result = $mapper->map('text_generation', []);

    expect($result)->toBe([]);
});

it('handles empty events', function (): void {
    $mapper = new PassthroughMapper;

    $result = $mapper->mapEvents([]);

    expect($result)->toBe([]);
});
