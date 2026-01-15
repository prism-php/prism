<?php

declare(strict_types=1);

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use Prism\Prism\Telemetry\Otel\SpanDataAdapter;

it('implements SpanDataInterface', function (): void {
    $adapter = createSpanDataAdapter();

    expect($adapter)->toBeInstanceOf(SpanDataInterface::class);
});

it('returns span name', function (): void {
    $adapter = createSpanDataAdapter(name: 'text_generation');

    expect($adapter->getName())->toBe('text_generation');
});

it('returns trace and span IDs', function (): void {
    $traceId = bin2hex(random_bytes(16));
    $spanId = bin2hex(random_bytes(8));

    $adapter = createSpanDataAdapter(traceId: $traceId, spanId: $spanId);

    expect($adapter->getTraceId())->toBe($traceId);
    expect($adapter->getSpanId())->toBe($spanId);
});

it('returns parent span ID', function (): void {
    $parentSpanId = bin2hex(random_bytes(8));

    $adapter = createSpanDataAdapter(parentSpanId: $parentSpanId);

    expect($adapter->getParentSpanId())->toBe($parentSpanId);
});

it('returns empty string when no parent span', function (): void {
    $adapter = createSpanDataAdapter(parentSpanId: null);

    expect($adapter->getParentSpanId())->toBe('');
});

it('returns OK status when no error', function (): void {
    $adapter = createSpanDataAdapter(hasError: false);

    expect($adapter->getStatus()->getCode())->toBe(StatusCode::STATUS_OK);
});

it('returns ERROR status when has error', function (): void {
    $adapter = createSpanDataAdapter(hasError: true);

    expect($adapter->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR);
});

it('returns start and end times', function (): void {
    $startTime = 1000000000;
    $endTime = 2000000000;

    $adapter = createSpanDataAdapter(startTimeNano: $startTime, endTimeNano: $endTime);

    expect($adapter->getStartEpochNanos())->toBe($startTime);
    expect($adapter->getEndEpochNanos())->toBe($endTime);
});

it('returns attributes', function (): void {
    $attributes = [
        'llm.model_name' => 'gpt-4',
        'llm.provider' => 'openai',
    ];

    $adapter = createSpanDataAdapter(attributes: $attributes);

    expect($adapter->getAttributes()->get('llm.model_name'))->toBe('gpt-4');
    expect($adapter->getAttributes()->get('llm.provider'))->toBe('openai');
});

it('converts events', function (): void {
    $events = [
        [
            'name' => 'exception',
            'timeNano' => 1500000000,
            'attributes' => [
                'exception.type' => 'RuntimeException',
                'exception.message' => 'Test error',
            ],
        ],
    ];

    $adapter = createSpanDataAdapter(events: $events);

    $adaptedEvents = $adapter->getEvents();
    expect($adaptedEvents)->toHaveCount(1);
    expect($adaptedEvents[0]->getName())->toBe('exception');
    expect($adaptedEvents[0]->getEpochNanos())->toBe(1500000000);
});

it('returns empty links', function (): void {
    $adapter = createSpanDataAdapter();

    expect($adapter->getLinks())->toBe([]);
});

it('returns INTERNAL span kind', function (): void {
    $adapter = createSpanDataAdapter();

    expect($adapter->getKind())->toBe(SpanKind::KIND_INTERNAL);
});

it('always reports as ended', function (): void {
    $adapter = createSpanDataAdapter();

    expect($adapter->hasEnded())->toBeTrue();
});

it('returns resource with service name', function (): void {
    $adapter = createSpanDataAdapter(serviceName: 'my-service');

    $resource = $adapter->getResource();
    expect($resource->getAttributes()->get('service.name'))->toBe('my-service');
    expect($resource->getAttributes()->get('telemetry.sdk.name'))->toBe('prism');
});

it('returns instrumentation scope', function (): void {
    $adapter = createSpanDataAdapter();

    $scope = $adapter->getInstrumentationScope();
    expect($scope->getName())->toBe('prism');
    expect($scope->getVersion())->toBe('1.0.0');
});

it('returns valid span context', function (): void {
    $traceId = bin2hex(random_bytes(16));
    $spanId = bin2hex(random_bytes(8));

    $adapter = createSpanDataAdapter(traceId: $traceId, spanId: $spanId);

    $context = $adapter->getContext();
    expect($context->getTraceId())->toBe($traceId);
    expect($context->getSpanId())->toBe($spanId);
    expect($context->isSampled())->toBeTrue();
});

it('returns valid parent context when parent exists', function (): void {
    $traceId = bin2hex(random_bytes(16));
    $parentSpanId = bin2hex(random_bytes(8));

    $adapter = createSpanDataAdapter(traceId: $traceId, parentSpanId: $parentSpanId);

    $parentContext = $adapter->getParentContext();
    expect($parentContext->isValid())->toBeTrue();
    expect($parentContext->getTraceId())->toBe($traceId);
    expect($parentContext->getSpanId())->toBe($parentSpanId);
});

it('returns invalid parent context when no parent', function (): void {
    $adapter = createSpanDataAdapter(parentSpanId: null);

    $parentContext = $adapter->getParentContext();
    expect($parentContext->isValid())->toBeFalse();
});

it('returns zero dropped events and links', function (): void {
    $adapter = createSpanDataAdapter();

    expect($adapter->getTotalDroppedEvents())->toBe(0);
    expect($adapter->getTotalDroppedLinks())->toBe(0);
});

// ============================================================================
// Helper Functions
// ============================================================================

function createSpanDataAdapter(
    string $name = 'test_operation',
    ?string $traceId = null,
    ?string $spanId = null,
    ?string $parentSpanId = null,
    int $startTimeNano = 1000000000,
    int $endTimeNano = 2000000000,
    array $attributes = [],
    array $events = [],
    bool $hasError = false,
    string $serviceName = 'prism'
): SpanDataAdapter {
    return new SpanDataAdapter(
        name: $name,
        traceId: $traceId ?? bin2hex(random_bytes(16)),
        spanId: $spanId ?? bin2hex(random_bytes(8)),
        parentSpanId: $parentSpanId,
        startTimeNano: $startTimeNano,
        endTimeNano: $endTimeNano,
        attributes: $attributes,
        events: $events,
        hasError: $hasError,
        serviceName: $serviceName
    );
}
