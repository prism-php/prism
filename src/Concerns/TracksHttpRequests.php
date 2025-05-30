<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Prism\Prism\Events\HttpRequestCompleted;
use Prism\Prism\Events\HttpRequestStarted;
use Throwable;

trait TracksHttpRequests
{
    protected ?string $parentContextId = null;

    protected function setTelemetryParentContext(?string $contextId): void
    {
        $this->parentContextId = $contextId;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function sendRequestWithTelemetry(
        callable $requestFunction,
        string $method,
        string $url,
        string $provider,
        array $attributes = []
    ): ClientResponse {
        $contextId = Str::uuid()->toString();

        Event::dispatch(new HttpRequestStarted(
            contextId: $contextId,
            parentContextId: $this->parentContextId,
            method: $method,
            url: $url,
            provider: $provider,
            attributes: $attributes
        ));

        try {
            $response = $requestFunction();

            Event::dispatch(new HttpRequestCompleted(
                contextId: $contextId,
                statusCode: $response->status(),
                attributes: array_merge($attributes, [
                    'success' => $response->successful(),
                    'response_size' => strlen($response->body()),
                ])
            ));

            return $response;
        } catch (Throwable $e) {
            Event::dispatch(new HttpRequestCompleted(
                contextId: $contextId,
                statusCode: 0,
                exception: $e,
                attributes: array_merge($attributes, [
                    'success' => false,
                    'error_type' => $e::class,
                ])
            ));

            throw $e;
        }
    }
}
