<?php

declare(strict_types=1);

namespace Prism\Prism;

use Closure;
use Illuminate\Support\Traits\Macroable;
use Prism\Prism\Audio\PendingRequest as PendingAudioRequest;
use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingRequest;
use Prism\Prism\Images\PendingRequest as PendingImageRequest;
use Prism\Prism\Moderation\PendingRequest as PendingModerationRequest;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;

class Prism
{
    use Macroable;

    /**
     * The callback to resolve default telemetry context.
     *
     * @var (Closure(): array<string, mixed>)|null
     */
    protected static ?Closure $defaultTelemetryContextResolver = null;

    /**
     * Set a callback to resolve default telemetry context.
     *
     * This context will be automatically added to all Prism requests.
     * Useful for adding user_id, session_id, etc. globally.
     *
     * The callback is invoked lazily per-request, so it's safe to reference
     * request-scoped services like auth() and session() inside the closure.
     *
     * @param  (Closure(): array<string, mixed>)|null  $callback
     *
     * @example
     * ```php
     * // In AppServiceProvider::boot():
     * Prism::defaultTelemetryContext(fn () => [
     *     'user_id' => auth()->id(),        // Resolved fresh each request
     *     'session_id' => session()->getId(),
     *     'environment' => app()->environment(),
     * ]);
     * ```
     *
     * @warning Do NOT capture request-scoped values in the closure:
     * ```php
     * // ❌ BAD - $user is captured, will leak between Octane requests
     * $user = auth()->user();
     * Prism::defaultTelemetryContext(fn () => ['user_id' => $user->id]);
     *
     * // ✅ GOOD - auth() is called fresh each request
     * Prism::defaultTelemetryContext(fn () => ['user_id' => auth()->id()]);
     * ```
     */
    public static function defaultTelemetryContext(?Closure $callback): void
    {
        static::$defaultTelemetryContextResolver = $callback;
    }

    /**
     * Get the default telemetry context.
     *
     * @return array<string, mixed>
     */
    public static function getDefaultTelemetryContext(): array
    {
        if (! static::$defaultTelemetryContextResolver instanceof \Closure) {
            return [];
        }

        return (static::$defaultTelemetryContextResolver)();
    }

    /**
     * Clear the default telemetry context resolver.
     */
    public static function flushDefaultTelemetryContext(): void
    {
        static::$defaultTelemetryContextResolver = null;
    }

    public function text(): PendingTextRequest
    {
        return new PendingTextRequest;
    }

    public function structured(): PendingStructuredRequest
    {
        return new PendingStructuredRequest;
    }

    public function embeddings(): PendingEmbeddingRequest
    {
        return new PendingEmbeddingRequest;
    }

    public function image(): PendingImageRequest
    {
        return new PendingImageRequest;
    }

    public function audio(): PendingAudioRequest
    {
        return new PendingAudioRequest;
    }

    public function moderation(): PendingModerationRequest
    {
        return new PendingModerationRequest;
    }
}
