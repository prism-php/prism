<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Prism\Prism\Embeddings\Response;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationCompleted;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationStarted;
use Prism\Prism\ValueObjects\Embedding;

it('emits telemetry events for embeddings when enabled', function (): void {
    config(['prism.telemetry.enabled' => true]);
    Event::fake();

    $mockResponse = new Response(
        embeddings: [
            new Embedding([1.0, 2.0, 3.0]),
        ],
        usage: new \Prism\Prism\ValueObjects\EmbeddingsUsage(10),
        meta: new \Prism\Prism\ValueObjects\Meta('test-id', 'test-model')
    );

    Prism::fake([$mockResponse]);

    $response = Prism::embeddings()
        ->using('openai', 'text-embedding-ada-002')
        ->fromInput('Test input')
        ->asEmbeddings();

    Event::assertDispatched(EmbeddingGenerationStarted::class);
    Event::assertDispatched(EmbeddingGenerationCompleted::class);

    expect($response)->toBeInstanceOf(Response::class);
});

it('does not emit telemetry events when disabled', function (): void {
    config(['prism.telemetry.enabled' => false]);
    Event::fake();

    $mockResponse = new Response(
        embeddings: [
            new Embedding([1.0, 2.0, 3.0]),
        ],
        usage: new \Prism\Prism\ValueObjects\EmbeddingsUsage(10),
        meta: new \Prism\Prism\ValueObjects\Meta('test-id', 'test-model')
    );

    Prism::fake([$mockResponse]);

    $response = Prism::embeddings()
        ->using('openai', 'text-embedding-ada-002')
        ->fromInput('Test input')
        ->asEmbeddings();

    Event::assertNotDispatched(EmbeddingGenerationStarted::class);
    Event::assertNotDispatched(EmbeddingGenerationCompleted::class);

    expect($response)->toBeInstanceOf(Response::class);
});

it('includes traceId and parentSpanId in embedding telemetry events', function (): void {
    config(['prism.telemetry.enabled' => true]);
    Event::fake();

    $mockResponse = new Response(
        embeddings: [
            new Embedding([1.0, 2.0, 3.0]),
        ],
        usage: new \Prism\Prism\ValueObjects\EmbeddingsUsage(10),
        meta: new \Prism\Prism\ValueObjects\Meta('test-id', 'test-model')
    );

    Prism::fake([$mockResponse]);

    Prism::embeddings()
        ->using('openai', 'text-embedding-ada-002')
        ->fromInput('Test input')
        ->asEmbeddings();

    Event::assertDispatched(EmbeddingGenerationStarted::class, fn ($event): bool => ! empty($event->spanId)
        && ! empty($event->traceId)
        && $event->request !== null);

    Event::assertDispatched(EmbeddingGenerationCompleted::class, fn ($event): bool => ! empty($event->spanId)
        && ! empty($event->traceId)
        && $event->request !== null
        && $event->response !== null);
});
