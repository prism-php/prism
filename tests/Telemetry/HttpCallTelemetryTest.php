<?php

declare(strict_types=1);

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Telemetry\Events\HttpCallCompleted;
use Prism\Prism\Telemetry\Events\HttpCallStarted;

class TelemetryMiddlewareTestClass
{
    use InitializesClient;

    public function getMiddleware(): Closure
    {
        return $this->telemetryMiddleware();
    }
}

beforeEach(function (): void {
    config(['prism.telemetry.enabled' => true]);
    Event::fake();
    Context::forgetHidden('prism.telemetry.trace_id');
    Context::forgetHidden('prism.telemetry.current_span_id');
});

describe('successful requests', function (): void {
    it('dispatches HttpCallStarted and HttpCallCompleted events', function (): void {
        $promise = executeMiddleware(new FulfilledPromise(new Response(200, [], 'OK')));
        $promise->wait();

        Event::assertDispatched(HttpCallStarted::class, fn ($e): bool => $e->method === 'POST'
                && $e->url === 'https://api.example.com/v1/test'
                && ! empty($e->spanId)
                && ! empty($e->traceId));

        Event::assertDispatched(HttpCallCompleted::class, fn ($e): bool => $e->method === 'POST'
                && $e->url === 'https://api.example.com/v1/test'
                && $e->statusCode === 200);
    });

    it('resets context span id to parent after completion', function (): void {
        $parentSpanId = 'parent-span-123';
        Context::addHidden('prism.telemetry.trace_id', 'trace-123');
        Context::addHidden('prism.telemetry.current_span_id', $parentSpanId);

        $promise = executeMiddleware(new FulfilledPromise(new Response(200)));
        $promise->wait();

        expect(Context::getHidden('prism.telemetry.current_span_id'))->toBe($parentSpanId);
    });
});

describe('failed requests', function (): void {
    it('dispatches HttpCallStarted but not HttpCallCompleted on failure', function (): void {
        $promise = executeMiddleware(new RejectedPromise(new Exception('Connection timeout')));

        try {
            $promise->wait();
        } catch (Exception) {
            // Expected
        }

        Event::assertDispatched(HttpCallStarted::class);
        Event::assertNotDispatched(HttpCallCompleted::class);
    });

    it('resets context span id to parent even on failure', function (): void {
        $parentSpanId = 'parent-span-456';
        Context::addHidden('prism.telemetry.trace_id', 'trace-456');
        Context::addHidden('prism.telemetry.current_span_id', $parentSpanId);

        $promise = executeMiddleware(new RejectedPromise(new Exception('Connection failed')));

        try {
            $promise->wait();
        } catch (Exception) {
            // Expected
        }

        expect(Context::getHidden('prism.telemetry.current_span_id'))->toBe($parentSpanId);
    });
});

describe('trace context', function (): void {
    it('preserves existing trace id across http calls', function (): void {
        $existingTraceId = 'existing-trace-id-789';
        Context::addHidden('prism.telemetry.trace_id', $existingTraceId);

        $promise = executeMiddleware(new FulfilledPromise(new Response(200)));
        $promise->wait();

        Event::assertDispatched(HttpCallStarted::class, fn ($e): bool => $e->traceId === $existingTraceId);
        Event::assertDispatched(HttpCallCompleted::class, fn ($e): bool => $e->traceId === $existingTraceId);
    });
});

function executeMiddleware($handlerResult, string $method = 'POST', string $url = 'https://api.example.com/v1/test')
{
    $testClass = new TelemetryMiddlewareTestClass;
    $middleware = $testClass->getMiddleware();

    $mockHandler = fn () => $handlerResult;
    $wrappedHandler = $middleware($mockHandler);

    return $wrappedHandler(new Request($method, $url), []);
}
