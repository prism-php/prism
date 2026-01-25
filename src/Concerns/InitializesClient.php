<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Telemetry\Events\HttpCallCompleted;
use Prism\Prism\Telemetry\Events\HttpCallStarted;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

trait InitializesClient
{
    protected function baseClient(): PendingRequest
    {
        $timeout = (int) config('prism.request_timeout');

        return Http::withRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request)
            ->withResponseMiddleware(fn (ResponseInterface $response): ResponseInterface => $response)
            ->timeout($timeout)
            ->connectTimeout($timeout)
            ->when(
                config('prism.telemetry.enabled', false),
                fn (PendingRequest $client) => $client->withMiddleware($this->telemetryMiddleware())
            )
            ->throw();
    }

    protected function telemetryMiddleware(): Closure
    {
        return fn (callable $handler): callable => function ($request, array $options) use ($handler) {
            $spanId = bin2hex(random_bytes(8));
            $traceId = Context::getHidden('prism.telemetry.trace_id') ?? bin2hex(random_bytes(16));
            $parentSpanId = Context::getHidden('prism.telemetry.current_span_id');

            // Use hidden context to avoid leaking telemetry IDs into logs
            Context::addHidden('prism.telemetry.trace_id', $traceId);
            Context::addHidden('prism.telemetry.current_span_id', $spanId);

            // Extract details from the PSR-7 request
            $method = $request->getMethod();
            $url = (string) $request->getUri();
            $headers = $request->getHeaders();

            Event::dispatch(new HttpCallStarted(
                spanId: $spanId,
                traceId: $traceId,
                parentSpanId: $parentSpanId,
                method: $method,
                url: $url,
                headers: $headers,
            ));

            $promise = $handler($request, $options);

            return $promise->then(
                function ($response) use ($spanId, $traceId, $parentSpanId, $method, $url) {
                    Event::dispatch(new HttpCallCompleted(
                        spanId: $spanId,
                        traceId: $traceId,
                        parentSpanId: $parentSpanId,
                        method: $method,
                        url: $url,
                        statusCode: $response->getStatusCode(),
                    ));

                    Context::addHidden('prism.telemetry.current_span_id', $parentSpanId);

                    return $response;
                },
                function (\Throwable $reason) use ($parentSpanId): void {
                    // Reset context even on HTTP errors
                    Context::addHidden('prism.telemetry.current_span_id', $parentSpanId);

                    throw $reason;
                }
            );
        };
    }
}
