<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Telemetry\Drivers\NullDriver;
use Prism\Prism\Telemetry\Events\TextGenerationCompleted;
use Prism\Prism\Telemetry\Events\TextGenerationStarted;
use Prism\Prism\Telemetry\Listeners\TelemetryEventListener;
use Prism\Prism\Telemetry\SpanCollector;
use Prism\Prism\Telemetry\SpanData;
use Prism\Prism\Text\Response;

it('dispatches telemetry events when telemetry is enabled', function (): void {
    config([
        'prism.telemetry.enabled' => true,
        'prism.telemetry.driver' => 'null',
    ]);

    Event::fake();

    $mockResponse = new Response(
        steps: collect(),
        text: 'Test response',
        finishReason: \Prism\Prism\Enums\FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        usage: new \Prism\Prism\ValueObjects\Usage(10, 20),
        meta: new \Prism\Prism\ValueObjects\Meta('test-id', 'test-model'),
        messages: collect()
    );

    Prism::fake([$mockResponse]);

    $response = Prism::text()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('Test prompt')
        ->asText();

    // Verify telemetry events were dispatched
    Event::assertDispatched(TextGenerationStarted::class);
    Event::assertDispatched(TextGenerationCompleted::class);

    expect($response)->toBeInstanceOf(Response::class);
});

it('does not dispatch events when telemetry is disabled', function (): void {
    config([
        'prism.telemetry.enabled' => false,
    ]);

    Event::fake();

    $mockResponse = new Response(
        steps: collect(),
        text: 'Test response',
        finishReason: \Prism\Prism\Enums\FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        usage: new \Prism\Prism\ValueObjects\Usage(10, 20),
        meta: new \Prism\Prism\ValueObjects\Meta('test-id', 'test-model'),
        messages: collect()
    );

    Prism::fake([$mockResponse]);

    $response = Prism::text()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('Test prompt')
        ->asText();

    // Verify events were not dispatched when telemetry is disabled
    Event::assertNotDispatched(TextGenerationStarted::class);
    Event::assertNotDispatched(TextGenerationCompleted::class);

    expect($response)->toBeInstanceOf(Response::class);
});

it('can handle telemetry events with event listener', function (): void {
    $driver = new NullDriver;
    $collector = new SpanCollector($driver);
    $listener = new TelemetryEventListener($collector);

    $textRequest = new \Prism\Prism\Text\Request(
        model: 'claude-3-sonnet',
        providerKey: 'anthropic:claude-3-sonnet',
        systemPrompts: [],
        prompt: 'test',
        messages: [],
        maxSteps: 1,
        maxTokens: null,
        temperature: null,
        topP: null,
        tools: [],
        clientOptions: [],
        clientRetry: [],
        toolChoice: null,
        providerOptions: [],
        providerTools: []
    );

    $startEvent = new TextGenerationStarted(
        spanId: 'span-123',
        traceId: 'trace-123',
        parentSpanId: null,
        request: $textRequest,
    );

    // This should not throw an exception
    $listener->handleTextGenerationStarted($startEvent);

    expect(true)->toBeTrue();
});

it('routes events through span collector and extracts attributes', function (): void {
    $capturedSpan = null;

    $driver = Mockery::mock(TelemetryDriver::class);
    $driver->shouldReceive('recordSpan')
        ->once()
        ->with(Mockery::on(function ($span) use (&$capturedSpan): bool {
            $capturedSpan = $span;

            return $span instanceof SpanData;
        }));

    $collector = new SpanCollector($driver);
    $listener = new TelemetryEventListener($collector);

    $textRequest = new \Prism\Prism\Text\Request(
        model: 'claude-3-sonnet',
        providerKey: 'anthropic:claude-3-sonnet',
        systemPrompts: [],
        prompt: 'test',
        messages: [],
        maxSteps: 1,
        maxTokens: null,
        temperature: null,
        topP: null,
        tools: [],
        clientOptions: [],
        clientRetry: [],
        toolChoice: null,
        providerOptions: [],
        providerTools: []
    );

    $textResponse = new Response(
        steps: collect(),
        text: 'Test response',
        finishReason: \Prism\Prism\Enums\FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        usage: new \Prism\Prism\ValueObjects\Usage(10, 20),
        meta: new \Prism\Prism\ValueObjects\Meta('test-id', 'test-model'),
        messages: collect()
    );

    $startEvent = new TextGenerationStarted(
        spanId: 'span-123',
        traceId: 'trace-123',
        parentSpanId: null,
        request: $textRequest,
    );
    $endEvent = new TextGenerationCompleted(
        spanId: 'span-123',
        traceId: 'trace-123',
        parentSpanId: null,
        request: $textRequest,
        response: $textResponse,
    );

    $listener->handleTextGenerationStarted($startEvent);
    $listener->handleTextGenerationCompleted($endEvent);

    // Verify span was created with generic Prism attributes (not OpenInference)
    expect($capturedSpan)->toBeInstanceOf(SpanData::class);
    expect($capturedSpan->operation)->toBe('text_generation');
    expect($capturedSpan->attributes)->toHaveKey('model');
    expect($capturedSpan->attributes['model'])->toBe('claude-3-sonnet');
    expect($capturedSpan->attributes)->toHaveKey('provider');
    expect($capturedSpan->attributes['provider'])->toBe('anthropic:claude-3-sonnet');
});
