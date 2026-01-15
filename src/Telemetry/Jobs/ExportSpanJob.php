<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Prism\Prism\Telemetry\Contracts\SemanticMapperInterface;
use Prism\Prism\Telemetry\Otel\OtlpExporter;
use Prism\Prism\Telemetry\Otel\SpanDataAdapter;
use Prism\Prism\Telemetry\Semantics\PassthroughMapper;
use Prism\Prism\Telemetry\SpanData;

class ExportSpanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 5;

    public function __construct(
        protected SpanData $spanData,
        protected string $driver = 'otlp'
    ) {
        $this->onQueue(config("prism.telemetry.drivers.{$driver}.queue", 'default'));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $config = config("prism.telemetry.drivers.{$this->driver}", []);

        // Resolve mapper from config
        $mapperClass = $config['mapper'] ?? PassthroughMapper::class;
        /** @var SemanticMapperInterface $mapper */
        $mapper = new $mapperClass;

        // Merge driver-level tags into attributes metadata
        $attributes = $this->spanData->attributes;
        if (! empty($config['tags'])) {
            $attributes['metadata'] = array_merge(
                $attributes['metadata'] ?? [],
                ['tags' => $config['tags']]
            );
        }

        // Map attributes using semantic mapper
        $mappedAttributes = $mapper->map(
            $this->spanData->operation,
            $attributes
        );
        $mappedEvents = $mapper->mapEvents($this->spanData->events);

        // Build headers
        $headers = [];
        if ($apiKey = $config['api_key'] ?? null) {
            $headers['Authorization'] = "Bearer {$apiKey}";
        }

        // Create exporter and export
        $exporter = new OtlpExporter(
            endpoint: $config['endpoint'] ?? 'http://localhost:4318/v1/traces',
            headers: $headers,
            timeout: (float) ($config['timeout'] ?? 30.0)
        );

        $span = new SpanDataAdapter(
            name: $this->spanData->operation,
            traceId: $this->spanData->traceId,
            spanId: $this->spanData->spanId,
            parentSpanId: $this->spanData->parentSpanId,
            startTimeNano: $this->spanData->startTimeNano,
            endTimeNano: $this->spanData->endTimeNano,
            attributes: $mappedAttributes,
            events: $mappedEvents,
            hasError: $this->spanData->hasError(),
            serviceName: $config['service_name'] ?? 'prism'
        );

        $exporter->export($span);
        $exporter->shutdown();
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'prism',
            'telemetry',
            $this->driver,
            'span:'.$this->spanData->operation,
        ];
    }
}
