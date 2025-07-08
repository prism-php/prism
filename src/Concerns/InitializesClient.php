<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Prism\Prism\Events\HttpRequestCompleted;
use Prism\Prism\Events\HttpRequestStarted;
use Prism\Prism\Http\Stream\StreamWrapper;
use Prism\Prism\Support\Trace;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

trait InitializesClient
{
    protected function baseClient(): PendingRequest
    {
        return Http::throw()
            ->withRequestMiddleware(
                function (RequestInterface $request): RequestInterface {
                    Trace::begin(
                        'http',
                        fn () => event(new HttpRequestStarted(
                            method: $request->getMethod(),
                            url: (string) $request->getUri(),
                            headers: Arr::mapWithKeys(
                                $request->getHeaders(),
                                fn ($value, $key) => [
                                    $key => in_array($key, ['Authentication', 'x-api-key', 'x-goog-api-key'])
                                        ? Str::mask($value[0], '*', 3)
                                        : $value,
                                ]
                            ),
                            attributes: json_decode((string) $request->getBody(), true),
                        )),
                    );

                    return $request;
                }
            )
            ->withResponseMiddleware(
                function (ResponseInterface $response): ResponseInterface {
                    if (! str_contains($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
                        Trace::end(
                            fn () => event(new HttpRequestCompleted(
                                statusCode: $response->getStatusCode(),
                                headers: $response->getHeaders(),
                                attributes: json_decode((string) $response->getBody(), true) ?? [],
                            ))
                        );

                        return $response;
                    }

                    // Wrap the stream to enable logging preserving the stream
                    $loggingStream = new StreamWrapper($response);

                    return $response->withBody($loggingStream);
                }
            );
    }
}
