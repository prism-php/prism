<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Prism\Prism\Telemetry\Events\HttpCallCompleted;
use Prism\Prism\Telemetry\Events\HttpCallStarted;

trait HasHttpClient
{
    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $options
     * @param  array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}|array{}  $retry
     */
    protected function createHttpClient(
        array $headers = [],
        array $options = [],
        array $retry = []
    ): PendingRequest {
        $client = Http::withHeaders($headers)
            ->withOptions($options);

        if ($retry !== []) {
            $client = $client->retry(...$retry);
        }

        if (config('prism.telemetry.enabled', false)) {
            return $client->withMiddleware($this->telemetryMiddleware());
        }

        return $client;
    }

    protected function telemetryMiddleware(): Closure
    {
        return fn (callable $handler): callable => function ($request, array $options) use ($handler) {
            if (! config('prism.telemetry.enabled', false)) {
                return $handler($request, $options);
            }

            $spanId = Str::uuid()->toString();
            $parentSpanId = Context::get('prism.telemetry.current_span_id');
            $rootSpanId = Context::get('prism.telemetry.root_span_id') ?? $spanId;

            Context::add('prism.telemetry.current_span_id', $spanId);
            Context::add('prism.telemetry.parent_span_id', $parentSpanId);

            // Extract details from the PSR-7 request
            $method = $request->getMethod();
            $url = (string) $request->getUri();
            $headers = $request->getHeaders();

            Event::dispatch(new HttpCallStarted(
                spanId: $spanId,
                method: $method,
                url: $url,
                headers: $headers,
                context: [
                    'parent_span_id' => $parentSpanId,
                    'root_span_id' => $rootSpanId,
                ]
            ));

            try {
                $promise = $handler($request, $options);

                return $promise->then(function ($response) use ($spanId, $method, $url, $parentSpanId, $rootSpanId) {
                    Event::dispatch(new HttpCallCompleted(
                        spanId: $spanId,
                        method: $method,
                        url: $url,
                        statusCode: $response->getStatusCode(),
                        context: [
                            'parent_span_id' => $parentSpanId,
                            'root_span_id' => $rootSpanId,
                        ]
                    ));

                    Context::add('prism.telemetry.current_span_id', $parentSpanId);

                    return $response;
                });
            } catch (\Throwable $e) {
                Context::add('prism.telemetry.current_span_id', $parentSpanId);
                throw $e;
            }
        };
    }
}
