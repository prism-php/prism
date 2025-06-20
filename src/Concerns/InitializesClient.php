<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

trait InitializesClient
{
    protected function baseClient(): PendingRequest
    {
        return Http::withRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request)
            ->withResponseMiddleware(fn (ResponseInterface $response): ResponseInterface => $response)
            ->throw();
    }
}
