<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\Otel\OtlpExporter;

it('reports SDK as available when installed', function (): void {
    // SDK is installed as dev dependency
    expect(OtlpExporter::isAvailable())->toBeTrue();
});

it('does not throw when SDK is available', function (): void {
    // Should not throw since SDK is installed
    OtlpExporter::ensureSdkAvailable();

    expect(true)->toBeTrue();
});
