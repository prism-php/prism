<?php

declare(strict_types=1);

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\StreamStartEvent;

it('constructs with required parameters', function (): void {
    $event = new StreamStartEvent(
        id: 'event-123',
        timestamp: 1640995200,
        model: 'gpt-4',
        provider: 'openai'
    );

    expect($event->id)->toBe('event-123')
        ->and($event->timestamp)->toBe(1640995200)
        ->and($event->model)->toBe('gpt-4')
        ->and($event->provider)->toBe('openai')
        ->and($event->metadata)->toBeNull();
});

it('constructs with metadata', function (): void {
    $metadata = ['temperature' => 0.7, 'max_tokens' => 100];

    $event = new StreamStartEvent(
        id: 'event-123',
        timestamp: 1640995200,
        model: 'claude-3-sonnet',
        provider: 'anthropic',
        metadata: $metadata
    );

    expect($event->metadata)->toBe($metadata);
});

it('returns correct stream event type', function (): void {
    $event = new StreamStartEvent(
        id: 'event-123',
        timestamp: 1640995200,
        model: 'gpt-4',
        provider: 'openai'
    );

    expect($event->type())->toBe(StreamEventType::StreamStart);
});

it('converts to array with all properties', function (): void {
    $metadata = ['temperature' => 0.7, 'max_tokens' => 100];

    $event = new StreamStartEvent(
        id: 'event-123',
        timestamp: 1640995200,
        model: 'claude-3-sonnet',
        provider: 'anthropic',
        metadata: $metadata
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'model' => 'claude-3-sonnet',
        'provider' => 'anthropic',
        'metadata' => $metadata,
    ]);
});

it('converts to array with null metadata', function (): void {
    $event = new StreamStartEvent(
        id: 'event-123',
        timestamp: 1640995200,
        model: 'gpt-4',
        provider: 'openai'
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'model' => 'gpt-4',
        'provider' => 'openai',
        'metadata' => null,
    ]);
});

it('handles empty string properties', function (): void {
    $event = new StreamStartEvent(
        id: '',
        timestamp: 0,
        model: '',
        provider: ''
    );

    expect($event->id)->toBe('')
        ->and($event->model)->toBe('')
        ->and($event->provider)->toBe('');
});

it('handles empty metadata array', function (): void {
    $event = new StreamStartEvent(
        id: 'event-123',
        timestamp: 1640995200,
        model: 'gpt-4',
        provider: 'openai',
        metadata: []
    );

    expect($event->metadata)->toBe([])
        ->and($event->toArray()['metadata'])->toBe([]);
});
