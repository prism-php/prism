<?php

declare(strict_types=1);

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;

it('constructs with required parameters', function (): void {
    $event = new ThinkingCompleteEvent(
        id: 'event-123',
        timestamp: 1640995200,
        reasoningId: 'reasoning-456'
    );

    expect($event->id)->toBe('event-123')
        ->and($event->timestamp)->toBe(1640995200)
        ->and($event->reasoningId)->toBe('reasoning-456')
        ->and($event->summary)->toBeNull();
});

it('constructs with summary', function (): void {
    $summary = [
        'conclusion' => 'The optimal solution is approach B',
        'confidence' => 0.95,
        'reasoning_time' => 5.2,
    ];

    $event = new ThinkingCompleteEvent(
        id: 'event-123',
        timestamp: 1640995200,
        reasoningId: 'reasoning-456',
        summary: $summary
    );

    expect($event->summary)->toBe($summary);
});

it('returns correct stream event type', function (): void {
    $event = new ThinkingCompleteEvent(
        id: 'event-123',
        timestamp: 1640995200,
        reasoningId: 'reasoning-456'
    );

    expect($event->type())->toBe(StreamEventType::ThinkingComplete);
});

it('converts to array with all properties', function (): void {
    $summary = [
        'final_answer' => 'The result is 42',
        'methodology' => 'deep thought',
        'certainty' => 0.99,
    ];

    $event = new ThinkingCompleteEvent(
        id: 'event-123',
        timestamp: 1640995200,
        reasoningId: 'reasoning-456',
        summary: $summary
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'reasoning_id' => 'reasoning-456',
        'summary' => $summary,
    ]);
});

it('converts to array with null summary', function (): void {
    $event = new ThinkingCompleteEvent(
        id: 'event-123',
        timestamp: 1640995200,
        reasoningId: 'reasoning-456'
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'reasoning_id' => 'reasoning-456',
        'summary' => null,
    ]);
});

it('handles complex summary with nested structures', function (): void {
    $summary = [
        'final_decision' => 'implement solution A',
        'analysis' => [
            'pros' => ['fast', 'memory-efficient'],
            'cons' => ['complex implementation'],
            'score' => 8.5,
        ],
        'alternatives_considered' => [
            ['name' => 'solution B', 'score' => 7.2],
            ['name' => 'solution C', 'score' => 6.8],
        ],
        'timestamps' => [
            'started' => 1640995100,
            'completed' => 1640995200,
        ],
    ];

    $event = new ThinkingCompleteEvent(
        id: 'event-123',
        timestamp: 1640995200,
        reasoningId: 'reasoning-456',
        summary: $summary
    );

    expect($event->summary)->toBe($summary)
        ->and($event->toArray()['summary'])->toBe($summary);
});

it('handles empty summary array', function (): void {
    $event = new ThinkingCompleteEvent(
        id: 'event-123',
        timestamp: 1640995200,
        reasoningId: 'reasoning-456',
        summary: []
    );

    expect($event->summary)->toBe([])
        ->and($event->toArray()['summary'])->toBe([]);
});

it('handles empty string reasoning id', function (): void {
    $event = new ThinkingCompleteEvent(
        id: 'event-123',
        timestamp: 1640995200,
        reasoningId: ''
    );

    expect($event->reasoningId)->toBe('');
});
