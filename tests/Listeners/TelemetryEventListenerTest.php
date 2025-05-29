<?php

declare(strict_types=1);

use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Events\PrismRequestCompleted;
use Prism\Prism\Events\PrismRequestStarted;
use Prism\Prism\Listeners\TelemetryEventListener;

it('handles prism request started and completed events', function (): void {
    $driver = Mockery::mock(TelemetryDriver::class);
    $listener = new TelemetryEventListener($driver, enabled: true);

    $driver->shouldReceive('startSpan')
        ->once()
        ->with(
            'text_generation',
            ['provider' => 'TestProvider', 'model' => 'test-model'],
            null
        )
        ->andReturn('span-123');

    $driver->shouldReceive('endSpan')
        ->once()
        ->with('span-123', Mockery::on(function (array $attributes): true {
            expect($attributes)->toHaveKey('duration_ms');
            expect($attributes['finish_reason'])->toBe('Stop');

            return true;
        }));

    $startEvent = new PrismRequestStarted(
        contextId: 'request-123',
        operationName: 'text_generation',
        attributes: ['provider' => 'TestProvider', 'model' => 'test-model']
    );

    $endEvent = new PrismRequestCompleted(
        contextId: 'request-123',
        attributes: ['finish_reason' => 'Stop']
    );

    $listener->handle($startEvent);
    $listener->handle($endEvent);
});

it('records exceptions when requests fail', function (): void {
    $driver = Mockery::mock(TelemetryDriver::class);
    $listener = new TelemetryEventListener($driver, enabled: true);
    $exception = new \Exception('Test error');

    $driver->shouldReceive('startSpan')->once()->andReturn('span-123');
    $driver->shouldReceive('recordException')
        ->once()
        ->with('span-123', $exception);
    $driver->shouldReceive('endSpan')->once();

    $startEvent = new PrismRequestStarted(
        contextId: 'request-123',
        operationName: 'text_generation'
    );

    $endEvent = new PrismRequestCompleted(
        contextId: 'request-123',
        exception: $exception
    );

    $listener->handle($startEvent);
    $listener->handle($endEvent);
});

it('does not handle events when disabled', function (): void {
    $driver = Mockery::mock(TelemetryDriver::class);
    $listener = new TelemetryEventListener($driver, enabled: false);

    $driver->shouldNotReceive('startSpan');

    $startEvent = new PrismRequestStarted(
        contextId: 'request-123',
        operationName: 'text_generation'
    );

    $listener->handle($startEvent);
});

it('handles HTTP request events', function (): void {
    $driver = Mockery::mock(TelemetryDriver::class);
    $listener = new TelemetryEventListener($driver, enabled: true);

    // Set up parent span first
    $driver->shouldReceive('startSpan')
        ->once()
        ->with('text_generation', [], null)
        ->andReturn('parent-span');

    $driver->shouldReceive('startSpan')
        ->once()
        ->with(
            'http_request',
            Mockery::on(function (array $attributes): true {
                expect($attributes)->toHaveKeys(['http.method', 'http.url', 'provider.name']);
                expect($attributes['http.method'])->toBe('POST');
                expect($attributes['http.url'])->toBe('https://api.openai.com/v1/chat/completions');
                expect($attributes['provider.name'])->toBe('OpenAI');

                return true;
            }),
            'parent-span'
        )
        ->andReturn('http-span');

    $driver->shouldReceive('endSpan')
        ->once()
        ->with('http-span', Mockery::on(function (array $attributes): true {
            expect($attributes)->toHaveKeys(['http.status_code', 'duration_ms']);
            expect($attributes['http.status_code'])->toBe(200);

            return true;
        }));

    // Start parent span
    $parentEvent = new \Prism\Prism\Events\PrismRequestStarted(
        contextId: 'parent-123',
        operationName: 'text_generation'
    );
    $listener->handle($parentEvent);

    // HTTP request events
    $httpStartEvent = new \Prism\Prism\Events\HttpRequestStarted(
        contextId: 'http-123',
        method: 'POST',
        url: 'https://api.openai.com/v1/chat/completions',
        provider: 'OpenAI',
        parentContextId: 'parent-123'
    );

    $httpEndEvent = new \Prism\Prism\Events\HttpRequestCompleted(
        contextId: 'http-123',
        statusCode: 200
    );

    $listener->handle($httpStartEvent);
    $listener->handle($httpEndEvent);
});

it('handles tool call events', function (): void {
    $driver = Mockery::mock(TelemetryDriver::class);
    $listener = new TelemetryEventListener($driver, enabled: true);

    // Set up parent span first
    $driver->shouldReceive('startSpan')
        ->once()
        ->with('text_generation', [], null)
        ->andReturn('parent-span');

    $driver->shouldReceive('startSpan')
        ->once()
        ->with(
            'tool_call',
            Mockery::on(function (array $attributes): true {
                expect($attributes)->toHaveKeys(['tool.name', 'tool.parameters']);
                expect($attributes['tool.name'])->toBe('get_weather');
                expect($attributes['tool.parameters'])->toBe('{"location":"San Francisco"}');

                return true;
            }),
            'parent-span'
        )
        ->andReturn('tool-span');

    $driver->shouldReceive('endSpan')
        ->once()
        ->with('tool-span', Mockery::on(function (array $attributes): true {
            expect($attributes)->toHaveKey('duration_ms');

            return true;
        }));

    // Start parent span
    $parentEvent = new \Prism\Prism\Events\PrismRequestStarted(
        contextId: 'parent-123',
        operationName: 'text_generation'
    );
    $listener->handle($parentEvent);

    // Tool call events
    $toolStartEvent = new \Prism\Prism\Events\ToolCallStarted(
        contextId: 'tool-123',
        toolName: 'get_weather',
        parameters: ['location' => 'San Francisco'],
        parentContextId: 'parent-123'
    );

    $toolEndEvent = new \Prism\Prism\Events\ToolCallCompleted(
        contextId: 'tool-123'
    );

    $listener->handle($toolStartEvent);
    $listener->handle($toolEndEvent);
});

it('handles events without parent context gracefully', function (): void {
    $driver = Mockery::mock(TelemetryDriver::class);
    $listener = new TelemetryEventListener($driver, enabled: true);

    $driver->shouldReceive('startSpan')
        ->once()
        ->with(
            'http_request',
            Mockery::any(),
            null // No parent should be found
        )
        ->andReturn('http-span');

    $httpStartEvent = new \Prism\Prism\Events\HttpRequestStarted(
        contextId: 'http-123',
        method: 'POST',
        url: 'https://api.openai.com/v1/chat/completions',
        provider: 'OpenAI',
        parentContextId: 'non-existent-parent'
    );

    $listener->handle($httpStartEvent);
});

it('ignores completion events without corresponding start events', function (): void {
    $driver = Mockery::mock(TelemetryDriver::class);
    $listener = new TelemetryEventListener($driver, enabled: true);

    $driver->shouldNotReceive('endSpan');
    $driver->shouldNotReceive('recordException');

    // End event without start
    $endEvent = new \Prism\Prism\Events\PrismRequestCompleted(
        contextId: 'non-existent-request'
    );

    $listener->handle($endEvent);
});

it('handles unknown event types gracefully', function (): void {
    $driver = Mockery::mock(TelemetryDriver::class);
    $listener = new TelemetryEventListener($driver, enabled: true);

    $driver->shouldNotReceive('startSpan');
    $driver->shouldNotReceive('endSpan');

    // Create a mock event that's not handled
    $unknownEvent = new class('test-123') extends \Prism\Prism\Events\TelemetryEvent {};

    $listener->handle($unknownEvent);
});
