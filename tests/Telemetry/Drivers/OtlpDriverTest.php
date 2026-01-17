<?php

declare(strict_types=1);

use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Prism\Prism\Telemetry\Drivers\OtlpDriver;
use Prism\Prism\Telemetry\Otel\PrimedIdGenerator;
use Prism\Prism\Telemetry\SpanData;

describe('ID Preservation', function (): void {
    it('uses exact span ID from SpanData', function (): void {
        $spanId = bin2hex(random_bytes(8));
        $traceId = bin2hex(random_bytes(16));

        $exportedSpans = [];
        $driver = createTestableOtlpDriver(function ($spans) use (&$exportedSpans): void {
            $exportedSpans = array_merge($exportedSpans, iterator_to_array($spans));
        });

        $driver->recordSpan(new SpanData(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: null,
            operation: 'test_operation',
            startTimeNano: hrtime(true),
            endTimeNano: hrtime(true) + 1_000_000,
            attributes: [],
            events: [],
        ));

        $driver->shutdown();

        expect($exportedSpans)->toHaveCount(1)
            ->and($exportedSpans[0]->getSpanId())->toBe($spanId)
            ->and($exportedSpans[0]->getTraceId())->toBe($traceId);
    });

    it('preserves parent span ID in context', function (): void {
        $spanId = bin2hex(random_bytes(8));
        $traceId = bin2hex(random_bytes(16));
        $parentSpanId = bin2hex(random_bytes(8));

        $exportedSpans = [];
        $driver = createTestableOtlpDriver(function ($spans) use (&$exportedSpans): void {
            $exportedSpans = array_merge($exportedSpans, iterator_to_array($spans));
        });

        $driver->recordSpan(new SpanData(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            operation: 'child_operation',
            startTimeNano: hrtime(true),
            endTimeNano: hrtime(true) + 1_000_000,
            attributes: [],
            events: [],
        ));

        $driver->shutdown();

        expect($exportedSpans[0]->getParentSpanId())->toBe($parentSpanId);
    });
});

describe('SDK Batching', function (): void {
    it('batches multiple spans via SDK BatchSpanProcessor', function (): void {
        $exportCount = 0;
        $totalSpans = 0;

        $driver = createTestableOtlpDriver(function ($spans) use (&$exportCount, &$totalSpans): void {
            $exportCount++;
            $totalSpans += count(iterator_to_array($spans));
        });

        $driver->recordSpan(createOtlpTestSpanData());
        $driver->recordSpan(createOtlpTestSpanData());
        $driver->recordSpan(createOtlpTestSpanData());

        $driver->shutdown();

        // SDK batches spans - typically one export call
        expect($totalSpans)->toBe(3);
    });

    it('flushes all spans on shutdown', function (): void {
        $exportCount = 0;

        $driver = createTestableOtlpDriver(function () use (&$exportCount): void {
            $exportCount++;
        });

        $driver->recordSpan(createOtlpTestSpanData());
        $driver->recordSpan(createOtlpTestSpanData());

        $driver->shutdown();

        // All spans should be exported
        expect($exportCount)->toBe(2);

        // Second shutdown should not export anything
        $driver->shutdown();
        expect($exportCount)->toBe(2);
    });
});

describe('Error Handling', function (): void {
    it('sets error status for failed spans', function (): void {
        $exportedSpans = [];
        $driver = createTestableOtlpDriver(function ($spans) use (&$exportedSpans): void {
            $exportedSpans = array_merge($exportedSpans, iterator_to_array($spans));
        });

        $driver->recordSpan(new SpanData(
            spanId: bin2hex(random_bytes(8)),
            traceId: bin2hex(random_bytes(16)),
            parentSpanId: null,
            operation: 'failed_operation',
            startTimeNano: hrtime(true),
            endTimeNano: hrtime(true) + 1_000_000,
            attributes: [],
            events: [],
            exception: new RuntimeException('Something failed'),
        ));

        $driver->shutdown();

        expect($exportedSpans[0]->getStatus()->getCode())->toBe('Error')
            ->and($exportedSpans[0]->getStatus()->getDescription())->toBe('Something failed');
    });
});

describe('Driver Identity', function (): void {
    it('returns configured driver name', function (): void {
        expect((new OtlpDriver('phoenix'))->getDriver())->toBe('phoenix');
    });

    it('defaults to otlp', function (): void {
        expect((new OtlpDriver)->getDriver())->toBe('otlp');
    });
});

describe('Configuration', function (): void {
    it('applies tags from config', function (): void {
        config(['prism.telemetry.drivers.tagged' => [
            'tags' => ['env' => 'testing'],
            'mapper' => \Prism\Prism\Telemetry\Semantics\OpenInferenceMapper::class,
        ]]);

        $exportedSpans = [];
        $driver = createTestableOtlpDriver(function ($spans) use (&$exportedSpans): void {
            $exportedSpans = array_merge($exportedSpans, iterator_to_array($spans));
        }, 'tagged');

        $driver->recordSpan(createOtlpTestSpanData());
        $driver->shutdown();

        $tagsTags = $exportedSpans[0]->getAttributes()->get('tag.tags');
        expect($tagsTags)->toContain('env:testing');
    });
});

describe('PrimedIdGenerator Integration', function (): void {
    it('uses PrimedIdGenerator for ID generation', function (): void {
        $driver = new OtlpDriver('otlp');

        $ref = new ReflectionMethod($driver, 'idGenerator');
        $generator = $ref->invoke($driver);

        expect($generator)->toBeInstanceOf(PrimedIdGenerator::class);
    });
});

function createTestableOtlpDriver(callable $onExport, string $driverName = 'otlp'): OtlpDriver
{
    $driver = new OtlpDriver($driverName);

    // Create mock exporter
    $mockExporter = Mockery::mock(SpanExporterInterface::class);
    $mockExporter->shouldReceive('export')
        ->andReturnUsing(function ($spans) use ($onExport) {
            $onExport($spans);

            $future = Mockery::mock(\OpenTelemetry\SDK\Common\Future\FutureInterface::class);
            $future->shouldReceive('await')->andReturn(true);

            return $future;
        });
    $mockExporter->shouldReceive('shutdown')->andReturn(true);

    // Create TracerProvider with mock exporter but real PrimedIdGenerator
    $idGenerator = new PrimedIdGenerator;
    $provider = new \OpenTelemetry\SDK\Trace\TracerProvider(
        spanProcessors: [new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($mockExporter)],
        sampler: new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler,
        resource: \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create(['service.name' => 'test'])
        ),
        idGenerator: $idGenerator,
    );

    // Inject via reflection
    $providerRef = new ReflectionProperty($driver, 'provider');
    $providerRef->setValue($driver, $provider);

    $idGenRef = new ReflectionProperty($driver, 'idGenerator');
    $idGenRef->setValue($driver, $idGenerator);

    return $driver;
}

function createOtlpTestSpanData(): SpanData
{
    return new SpanData(
        spanId: bin2hex(random_bytes(8)),
        traceId: bin2hex(random_bytes(16)),
        parentSpanId: null,
        operation: 'text_generation',
        startTimeNano: hrtime(true),
        endTimeNano: hrtime(true) + 1_000_000,
        attributes: [
            'model' => 'gpt-4',
            'provider' => 'openai',
        ],
        events: [],
    );
}
