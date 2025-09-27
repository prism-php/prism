<?php

declare(strict_types=1);

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\ThinkingEvent;

it('constructs with required parameters', function (): void {
    $event = new ThinkingEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: 'Let me think about this...',
        reasoningId: 'reasoning-456'
    );

    expect($event->id)->toBe('event-123')
        ->and($event->timestamp)->toBe(1640995200)
        ->and($event->delta)->toBe('Let me think about this...')
        ->and($event->reasoningId)->toBe('reasoning-456')
        ->and($event->summary)->toBeNull();
});

it('constructs with summary', function (): void {
    $summary = [
        'topic' => 'mathematics',
        'confidence' => 0.85,
        'steps' => ['analyze', 'compute', 'validate'],
    ];

    $event = new ThinkingEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: 'Calculating the result...',
        reasoningId: 'reasoning-456',
        summary: $summary
    );

    expect($event->summary)->toBe($summary);
});

it('returns correct stream event type', function (): void {
    $event = new ThinkingEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: 'Thinking...',
        reasoningId: 'reasoning-456'
    );

    expect($event->type())->toBe(StreamEventType::ThinkingDelta);
});

it('converts to array with all properties', function (): void {
    $summary = [
        'topic' => 'physics',
        'confidence' => 0.92,
    ];

    $event = new ThinkingEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: 'Considering quantum mechanics...',
        reasoningId: 'reasoning-456',
        summary: $summary
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'delta' => 'Considering quantum mechanics...',
        'reasoning_id' => 'reasoning-456',
        'summary' => $summary,
    ]);
});

it('converts to array with null summary', function (): void {
    $event = new ThinkingEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: 'Thinking deeply...',
        reasoningId: 'reasoning-456'
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'delta' => 'Thinking deeply...',
        'reasoning_id' => 'reasoning-456',
        'summary' => null,
    ]);
});

it('handles empty string delta', function (): void {
    $event = new ThinkingEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: '',
        reasoningId: 'reasoning-456'
    );

    expect($event->delta)->toBe('');
});

it('handles multiline thinking delta', function (): void {
    $delta = "First, I need to consider:\n1. The problem context\n2. Available data\n3. Constraints";

    $event = new ThinkingEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: $delta,
        reasoningId: 'reasoning-456'
    );

    expect($event->delta)->toBe($delta);
});

it('handles complex summary structure', function (): void {
    $summary = [
        'main_topic' => 'algorithm analysis',
        'confidence_score' => 0.78,
        'reasoning_steps' => [
            ['step' => 'analyze', 'duration' => 2.5],
            ['step' => 'validate', 'duration' => 1.2],
        ],
        'metadata' => [
            'complexity' => 'O(n log n)',
            'memory_usage' => 'O(n)',
        ],
    ];

    $event = new ThinkingEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: 'Analyzing time complexity...',
        reasoningId: 'reasoning-456',
        summary: $summary
    );

    expect($event->summary)->toBe($summary)
        ->and($event->toArray()['summary'])->toBe($summary);
});

it('handles empty summary array', function (): void {
    $event = new ThinkingEvent(
        id: 'event-123',
        timestamp: 1640995200,
        delta: 'Starting to think...',
        reasoningId: 'reasoning-456',
        summary: []
    );

    expect($event->summary)->toBe([])
        ->and($event->toArray()['summary'])->toBe([]);
});
