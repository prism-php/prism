<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Otel;

use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use RuntimeException;

/**
 * Wrapper for OpenTelemetry OTLP exporter.
 *
 * Provides a simple interface for exporting spans to OTLP endpoints.
 */
class OtlpExporter
{
    private readonly SpanExporterInterface $exporter;

    /**
     * @param  array<string, string>  $headers  Additional headers (e.g., Authorization)
     */
    public function __construct(
        string $endpoint,
        array $headers = [],
        float $timeout = 30.0,
    ) {
        self::ensureSdkAvailable();

        $transport = (new OtlpHttpTransportFactory)->create(
            endpoint: $endpoint,
            contentType: ContentTypes::JSON,
            headers: $headers,
            timeout: $timeout,
        );

        $this->exporter = new SpanExporter($transport);
    }

    /**
     * Export a span using the OTEL SDK.
     */
    public function export(SpanDataAdapter $span): bool
    {
        $result = $this->exporter->export([$span]);

        return $result->await();
    }

    /**
     * Shutdown the exporter.
     */
    public function shutdown(): bool
    {
        return $this->exporter->shutdown();
    }

    /**
     * Check if the OTEL SDK is available.
     */
    public static function isAvailable(): bool
    {
        return class_exists(SpanExporter::class)
            && class_exists(OtlpHttpTransportFactory::class);
    }

    /**
     * Ensure the OTEL SDK is installed.
     *
     * @throws RuntimeException
     */
    public static function ensureSdkAvailable(): void
    {
        if (! self::isAvailable()) {
            throw new RuntimeException(
                'OpenTelemetry SDK is required for this telemetry driver. '.
                'Install it with: composer require open-telemetry/sdk open-telemetry/exporter-otlp'
            );
        }
    }
}
