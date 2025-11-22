<?php

declare(strict_types=1);

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\ErrorEvent;

it('constructs with required parameters', function (): void {
    $event = new ErrorEvent(
        id: 'event-123',
        timestamp: 1640995200,
        errorType: 'rate_limit',
        message: 'Rate limit exceeded: 100 requests per minute',
        recoverable: true
    );

    expect($event->id)->toBe('event-123')
        ->and($event->timestamp)->toBe(1640995200)
        ->and($event->errorType)->toBe('rate_limit')
        ->and($event->message)->toBe('Rate limit exceeded: 100 requests per minute')
        ->and($event->recoverable)->toBeTrue()
        ->and($event->metadata)->toBeNull();
});

it('constructs with metadata', function (): void {
    $metadata = [
        'retry_after' => 60,
        'request_id' => 'req-456',
        'endpoint' => '/api/chat',
    ];

    $event = new ErrorEvent(
        id: 'event-123',
        timestamp: 1640995200,
        errorType: 'validation',
        message: 'Invalid request format',
        recoverable: false,
        metadata: $metadata
    );

    expect($event->metadata)->toBe($metadata);
});

it('returns correct stream event type', function (): void {
    $event = new ErrorEvent(
        id: 'event-123',
        timestamp: 1640995200,
        errorType: 'network',
        message: 'Connection failed',
        recoverable: true
    );

    expect($event->type())->toBe(StreamEventType::Error);
});

it('converts to array with all properties', function (): void {
    $metadata = [
        'http_status' => 500,
        'error_code' => 'INTERNAL_ERROR',
        'details' => ['component' => 'database', 'query' => 'failed'],
    ];

    $event = new ErrorEvent(
        id: 'event-123',
        timestamp: 1640995200,
        errorType: 'internal',
        message: 'Internal server error occurred',
        recoverable: false,
        metadata: $metadata
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'error_type' => 'internal',
        'message' => 'Internal server error occurred',
        'recoverable' => false,
        'metadata' => $metadata,
    ]);
});

it('converts to array with null metadata', function (): void {
    $event = new ErrorEvent(
        id: 'event-123',
        timestamp: 1640995200,
        errorType: 'timeout',
        message: 'Request timeout after 30 seconds',
        recoverable: true
    );

    $array = $event->toArray();

    expect($array)->toBe([
        'id' => 'event-123',
        'timestamp' => 1640995200,
        'error_type' => 'timeout',
        'message' => 'Request timeout after 30 seconds',
        'recoverable' => true,
        'metadata' => null,
    ]);
});

it('handles different error types', function (string $errorType): void {
    $event = new ErrorEvent(
        id: 'event-123',
        timestamp: 1640995200,
        errorType: $errorType,
        message: "Error of type {$errorType}",
        recoverable: true
    );

    expect($event->errorType)->toBe($errorType)
        ->and($event->toArray()['error_type'])->toBe($errorType);
})->with([
    'rate_limit',
    'validation',
    'authentication',
    'authorization',
    'network',
    'timeout',
    'internal',
    'service_unavailable',
    'quota_exceeded',
    'model_overloaded',
]);

it('handles recoverable and non-recoverable errors', function (bool $recoverable): void {
    $event = new ErrorEvent(
        id: 'event-123',
        timestamp: 1640995200,
        errorType: 'test',
        message: 'Test error',
        recoverable: $recoverable
    );

    expect($event->recoverable)->toBe($recoverable)
        ->and($event->toArray()['recoverable'])->toBe($recoverable);
})->with([true, false]);

it('handles empty string error message', function (): void {
    $event = new ErrorEvent(
        id: 'event-123',
        timestamp: 1640995200,
        errorType: 'unknown',
        message: '',
        recoverable: false
    );

    expect($event->message)->toBe('');
});

it('handles multiline error message', function (): void {
    $message = "Multiple errors occurred:\n1. Connection failed\n2. Retry limit exceeded\n3. Request cancelled";

    $event = new ErrorEvent(
        id: 'event-123',
        timestamp: 1640995200,
        errorType: 'multiple',
        message: $message,
        recoverable: false
    );

    expect($event->message)->toBe($message);
});

it('handles complex metadata structure', function (): void {
    $metadata = [
        'request_details' => [
            'method' => 'POST',
            'url' => '/api/v1/chat',
            'headers' => ['Content-Type' => 'application/json'],
            'body_size' => 1024,
        ],
        'error_chain' => [
            ['type' => 'NetworkError', 'message' => 'DNS resolution failed'],
            ['type' => 'RetryError', 'message' => 'Max retries exceeded'],
        ],
        'context' => [
            'user_id' => 'user-789',
            'session_id' => 'session-101',
            'timestamp' => 1640995200,
        ],
        'system_info' => [
            'version' => '1.2.3',
            'environment' => 'production',
            'region' => 'us-east-1',
        ],
    ];

    $event = new ErrorEvent(
        id: 'event-123',
        timestamp: 1640995200,
        errorType: 'complex',
        message: 'Complex error with detailed metadata',
        recoverable: true,
        metadata: $metadata
    );

    expect($event->metadata)->toBe($metadata)
        ->and($event->toArray()['metadata'])->toBe($metadata);
});

it('handles empty metadata array', function (): void {
    $event = new ErrorEvent(
        id: 'event-123',
        timestamp: 1640995200,
        errorType: 'empty_metadata',
        message: 'Error with empty metadata',
        recoverable: true,
        metadata: []
    );

    expect($event->metadata)->toBe([])
        ->and($event->toArray()['metadata'])->toBe([]);
});

it('handles special characters in error message', function (): void {
    $message = "Error with special chars: \"quotes\", 'apostrophes', <tags>, &entities;, émojis 🚨";

    $event = new ErrorEvent(
        id: 'event-123',
        timestamp: 1640995200,
        errorType: 'special_chars',
        message: $message,
        recoverable: false
    );

    expect($event->message)->toBe($message);
});

it('handles very long error message', function (): void {
    $message = str_repeat('This is a very long error message. ', 100);

    $event = new ErrorEvent(
        id: 'event-123',
        timestamp: 1640995200,
        errorType: 'long_message',
        message: $message,
        recoverable: true
    );

    expect($event->message)->toBe($message);
});

it('handles empty string properties', function (): void {
    $event = new ErrorEvent(
        id: '',
        timestamp: 0,
        errorType: '',
        message: '',
        recoverable: false
    );

    expect($event->id)->toBe('')
        ->and($event->errorType)->toBe('')
        ->and($event->message)->toBe('');
});
