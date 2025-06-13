<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Prism\Prism\Prism;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Response;
use Prism\Prism\Telemetry\Events\StructuredOutputCompleted;
use Prism\Prism\Telemetry\Events\StructuredOutputStarted;

it('emits telemetry events for structured output when enabled', function (): void {
    config(['prism.telemetry.enabled' => true]);
    Event::fake();

    $mockResponse = new Response(
        steps: collect(),
        responseMessages: collect(),
        text: 'Structured response',
        structured: ['test'],
        finishReason: \Prism\Prism\Enums\FinishReason::Stop,
        usage: new \Prism\Prism\ValueObjects\Usage(10, 20),
        meta: new \Prism\Prism\ValueObjects\Meta('test-id', 'test-model')
    );

    Prism::fake([$mockResponse]);

    $response = Prism::structured()
        ->using('openai', 'gpt-4')
        ->withPrompt('Generate structured data')
        ->withSchema(new StringSchema('test', 'Test schema'))
        ->asStructured();

    Event::assertDispatched(StructuredOutputStarted::class);
    Event::assertDispatched(StructuredOutputCompleted::class);

    expect($response)->toBeInstanceOf(Response::class);
});

it('does not emit telemetry events when disabled', function (): void {
    config(['prism.telemetry.enabled' => false]);
    Event::fake();

    $mockResponse = new Response(
        steps: collect(),
        responseMessages: collect(),
        text: 'Structured response',
        structured: ['test'],
        finishReason: \Prism\Prism\Enums\FinishReason::Stop,
        usage: new \Prism\Prism\ValueObjects\Usage(10, 20),
        meta: new \Prism\Prism\ValueObjects\Meta('test-id', 'test-model')
    );

    Prism::fake([$mockResponse]);

    $response = Prism::structured()
        ->using('openai', 'gpt-4')
        ->withPrompt('Generate structured data')
        ->withSchema(new StringSchema('test', 'Test schema'))
        ->asStructured();

    Event::assertNotDispatched(StructuredOutputStarted::class);
    Event::assertNotDispatched(StructuredOutputCompleted::class);

    expect($response)->toBeInstanceOf(Response::class);
});

it('includes context in structured output telemetry events', function (): void {
    config(['prism.telemetry.enabled' => true]);
    Event::fake();

    $mockResponse = new Response(
        steps: collect(),
        responseMessages: collect(),
        text: 'Structured response',
        structured: ['test'],
        finishReason: \Prism\Prism\Enums\FinishReason::Stop,
        usage: new \Prism\Prism\ValueObjects\Usage(10, 20),
        meta: new \Prism\Prism\ValueObjects\Meta('test-id', 'test-model')
    );

    Prism::fake([$mockResponse]);

    Prism::structured()
        ->using('openai', 'gpt-4')
        ->withPrompt('Generate structured data')
        ->withSchema(new StringSchema('test', 'Test schema'))
        ->asStructured();

    Event::assertDispatched(StructuredOutputStarted::class, fn ($event): bool => ! empty($event->spanId)
        && $event->request !== null
        && is_array($event->context));

    Event::assertDispatched(StructuredOutputCompleted::class, fn ($event): bool => ! empty($event->spanId)
        && $event->request !== null
        && $event->response !== null
        && is_array($event->context));
});
