<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Telemetry\Events\TextGenerationCompleted;
use Prism\Prism\Telemetry\Events\TextGenerationStarted;
use Prism\Prism\Text\Response;

it('emits telemetry events for text generation when enabled', function (): void {
    config(['prism.telemetry.enabled' => true]);
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
        ->using('openai', 'gpt-4')
        ->withPrompt('Test prompt')
        ->asText();

    Event::assertDispatched(TextGenerationStarted::class);
    Event::assertDispatched(TextGenerationCompleted::class);

    expect($response)->toBeInstanceOf(Response::class);
});

it('does not emit telemetry events when disabled', function (): void {
    config(['prism.telemetry.enabled' => false]);
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
        ->using('openai', 'gpt-4')
        ->withPrompt('Test prompt')
        ->asText();

    Event::assertNotDispatched(TextGenerationStarted::class);
    Event::assertNotDispatched(TextGenerationCompleted::class);

    expect($response)->toBeInstanceOf(Response::class);
});

it('includes traceId and parentSpanId in telemetry events', function (): void {
    config(['prism.telemetry.enabled' => true]);
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

    Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test prompt')
        ->asText();

    Event::assertDispatched(TextGenerationStarted::class, fn ($event): bool => ! empty($event->spanId)
        && ! empty($event->traceId)
        && $event->request !== null);

    Event::assertDispatched(TextGenerationCompleted::class, fn ($event): bool => ! empty($event->spanId)
        && ! empty($event->traceId)
        && $event->request !== null
        && $event->response !== null);
});
