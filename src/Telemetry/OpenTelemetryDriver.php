<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use Prism\Prism\Contracts\Telemetry;
use Throwable;

class OpenTelemetryDriver implements Telemetry
{
    public function __construct(
        protected TracerInterface $tracer,
        protected bool $enabled
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function span(string $spanName, array $attributes, callable $callback): mixed
    {
        if (! $this->enabled) {
            return $callback();
        }

        return $this->executeWithSpan($spanName, $attributes, $callback);
    }

    public function childSpan(string $spanName, array $attributes, callable $callback): mixed
    {
        if (! $this->enabled) {
            return $callback();
        }

        return $this->executeWithSpan($spanName, $attributes, $callback, useCurrentContext: true);
    }

    /**
     * @template T
     *
     * @param  non-empty-string  $spanName
     * @param  array<non-empty-string, mixed>  $attributes
     * @param  callable(): T  $callback
     * @return T
     */
    protected function executeWithSpan(string $spanName, array $attributes, callable $callback, bool $useCurrentContext = false): mixed
    {
        $spanBuilder = $this->tracer->spanBuilder($spanName);

        if ($useCurrentContext) {
            $spanBuilder->setParent(Context::getCurrent());
        }

        $span = $spanBuilder->startSpan();

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        $scope = $span->activate();

        try {
            $result = $callback();
            $span->setStatus(StatusCode::STATUS_OK);

            return $result;
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
