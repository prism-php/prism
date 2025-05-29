<?php

declare(strict_types=1);

use Prism\Prism\Events\HttpRequestCompleted;
use Prism\Prism\Events\HttpRequestStarted;
use Prism\Prism\Events\PrismRequestCompleted;
use Prism\Prism\Events\PrismRequestStarted;
use Prism\Prism\Events\TelemetryEvent;
use Prism\Prism\Events\ToolCallCompleted;
use Prism\Prism\Events\ToolCallStarted;

it('creates PrismRequestStarted event correctly', function (): void {
    $event = new PrismRequestStarted(
        contextId: 'test-123',
        operationName: 'text_generation',
        attributes: ['provider' => 'OpenAI', 'model' => 'gpt-4']
    );

    expect($event)->toBeInstanceOf(TelemetryEvent::class);
    expect($event->contextId)->toBe('test-123');
    expect($event->operationName)->toBe('text_generation');
    expect($event->parentContextId)->toBeNull();
    expect($event->attributes)->toBe(['provider' => 'OpenAI', 'model' => 'gpt-4']);
});

it('creates PrismRequestCompleted event correctly', function (): void {
    $exception = new \Exception('Test error');

    $event = new PrismRequestCompleted(
        contextId: 'test-123',
        exception: $exception,
        attributes: ['status' => 'failed']
    );

    expect($event)->toBeInstanceOf(TelemetryEvent::class);
    expect($event->contextId)->toBe('test-123');
    expect($event->exception)->toBe($exception);
    expect($event->parentContextId)->toBeNull();
    expect($event->attributes)->toBe(['status' => 'failed']);
});

it('creates HttpRequestStarted event correctly', function (): void {
    $event = new HttpRequestStarted(
        contextId: 'http-123',
        method: 'POST',
        url: 'https://api.openai.com/v1/chat/completions',
        provider: 'OpenAI',
        parentContextId: 'parent-123',
        attributes: ['timeout' => 30]
    );

    expect($event)->toBeInstanceOf(TelemetryEvent::class);
    expect($event->contextId)->toBe('http-123');
    expect($event->method)->toBe('POST');
    expect($event->url)->toBe('https://api.openai.com/v1/chat/completions');
    expect($event->provider)->toBe('OpenAI');
    expect($event->parentContextId)->toBe('parent-123');
    expect($event->attributes)->toBe(['timeout' => 30]);
});

it('creates HttpRequestCompleted event correctly', function (): void {
    $exception = new \RuntimeException('HTTP error');

    $event = new HttpRequestCompleted(
        contextId: 'http-123',
        statusCode: 500,
        exception: $exception,
        attributes: ['response_size' => 1024]
    );

    expect($event)->toBeInstanceOf(TelemetryEvent::class);
    expect($event->contextId)->toBe('http-123');
    expect($event->statusCode)->toBe(500);
    expect($event->exception)->toBe($exception);
    expect($event->parentContextId)->toBeNull();
    expect($event->attributes)->toBe(['response_size' => 1024]);
});

it('creates ToolCallStarted event correctly', function (): void {
    $parameters = ['location' => 'San Francisco', 'units' => 'celsius'];

    $event = new ToolCallStarted(
        contextId: 'tool-123',
        toolName: 'get_weather',
        parameters: $parameters,
        parentContextId: 'parent-123',
        attributes: ['tool_version' => '1.0']
    );

    expect($event)->toBeInstanceOf(TelemetryEvent::class);
    expect($event->contextId)->toBe('tool-123');
    expect($event->toolName)->toBe('get_weather');
    expect($event->parameters)->toBe($parameters);
    expect($event->parentContextId)->toBe('parent-123');
    expect($event->attributes)->toBe(['tool_version' => '1.0']);
});

it('creates ToolCallCompleted event correctly', function (): void {
    $exception = new \InvalidArgumentException('Invalid tool parameters');

    $event = new ToolCallCompleted(
        contextId: 'tool-123',
        exception: $exception,
        attributes: ['execution_time' => 0.5]
    );

    expect($event)->toBeInstanceOf(TelemetryEvent::class);
    expect($event->contextId)->toBe('tool-123');
    expect($event->exception)->toBe($exception);
    expect($event->parentContextId)->toBeNull();
    expect($event->attributes)->toBe(['execution_time' => 0.5]);
});

it('supports events without attributes', function (): void {
    $event = new PrismRequestStarted(
        contextId: 'test-123',
        operationName: 'text_generation'
    );

    expect($event->attributes)->toBe([]);
});

it('supports events without exceptions', function (): void {
    $event = new PrismRequestCompleted(
        contextId: 'test-123'
    );

    expect($event->exception)->toBeNull();
});

it('supports events without parent context', function (): void {
    $event = new HttpRequestStarted(
        contextId: 'http-123',
        method: 'GET',
        url: 'https://api.example.com/data',
        provider: 'Example'
    );

    expect($event->parentContextId)->toBeNull();
});
