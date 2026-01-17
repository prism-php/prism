<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationCompleted;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationStarted;
use Prism\Prism\Telemetry\Events\HttpCallCompleted;
use Prism\Prism\Telemetry\Events\HttpCallStarted;
use Prism\Prism\Telemetry\Events\SpanException;
use Prism\Prism\Telemetry\Events\StreamingCompleted;
use Prism\Prism\Telemetry\Events\StreamingStarted;
use Prism\Prism\Telemetry\Events\StructuredOutputCompleted;
use Prism\Prism\Telemetry\Events\StructuredOutputStarted;
use Prism\Prism\Telemetry\Events\TextGenerationCompleted;
use Prism\Prism\Telemetry\Events\TextGenerationStarted;
use Prism\Prism\Telemetry\Events\ToolCallCompleted;
use Prism\Prism\Telemetry\Events\ToolCallStarted;
use Prism\Prism\Telemetry\Listeners\TelemetryEventListener;

class TelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TelemetryManager::class);

        $this->app->scoped(TelemetryDriver::class, function () {
            $manager = $this->app->make(TelemetryManager::class);
            $driver = config('prism.telemetry.driver', 'null');

            return $manager->resolve($driver);
        });

        $this->app->scoped(SpanCollector::class, fn (): SpanCollector => new SpanCollector(
            $this->app->make(TelemetryDriver::class)
        ));

        $this->app->scoped(TelemetryEventListener::class, fn (): TelemetryEventListener => new TelemetryEventListener(
            $this->app->make(SpanCollector::class)
        ));
    }

    public function boot(): void
    {
        if (config('prism.telemetry.enabled', false)) {
            $this->registerEventListeners();
        }
    }

    protected function registerEventListeners(): void
    {
        $listener = $this->app->make(TelemetryEventListener::class);

        Event::listen(TextGenerationStarted::class, $listener->handleTextGenerationStarted(...));
        Event::listen(TextGenerationCompleted::class, $listener->handleTextGenerationCompleted(...));
        Event::listen(StructuredOutputStarted::class, $listener->handleStructuredOutputStarted(...));
        Event::listen(StructuredOutputCompleted::class, $listener->handleStructuredOutputCompleted(...));
        Event::listen(EmbeddingGenerationStarted::class, $listener->handleEmbeddingGenerationStarted(...));
        Event::listen(EmbeddingGenerationCompleted::class, $listener->handleEmbeddingGenerationCompleted(...));
        Event::listen(StreamingStarted::class, $listener->handleStreamingStarted(...));
        Event::listen(StreamingCompleted::class, $listener->handleStreamingCompleted(...));
        Event::listen(HttpCallStarted::class, $listener->handleHttpCallStarted(...));
        Event::listen(HttpCallCompleted::class, $listener->handleHttpCallCompleted(...));
        Event::listen(ToolCallStarted::class, $listener->handleToolCallStarted(...));
        Event::listen(ToolCallCompleted::class, $listener->handleToolCallCompleted(...));
        Event::listen(SpanException::class, $listener->handleSpanException(...));
    }
}
