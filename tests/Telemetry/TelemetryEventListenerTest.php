<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Prism\Prism\Prism;
use Prism\Prism\Telemetry\Drivers\NullDriver;
use Prism\Prism\Telemetry\Events\TextGenerationCompleted;
use Prism\Prism\Telemetry\Events\TextGenerationStarted;
use Prism\Prism\Telemetry\Listeners\TelemetryEventListener;
use Prism\Prism\Text\Response;

it('dispatches telemetry events when telemetry is enabled', function (): void {
    config([
        'prism.telemetry.enabled' => true,
        'prism.telemetry.driver' => 'null',
    ]);

    Event::fake();

    $mockResponse = new Response(
        steps: collect(),
        responseMessages: collect(),
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
        responseMessages: collect(),
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
    $listener = new TelemetryEventListener($driver);

    $textRequest = new \Prism\Prism\Text\Request(
        model: 'claude-3-sonnet',
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

    $startEvent = new TextGenerationStarted('span-123', $textRequest, []);

    // This should not throw an exception
    $listener->handleTextGenerationStarted($startEvent);

    expect(true)->toBeTrue();
});
