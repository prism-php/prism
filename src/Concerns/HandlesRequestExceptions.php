<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Http\Client\RequestException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Throwable;

trait HandlesRequestExceptions
{
    public function handleRequestException(string $model, Throwable $e): never
    {
        // Keep already raised PrismException
        if ($e instanceof PrismException) {
            throw $e;
        }

        if (! $e instanceof RequestException) {
            throw PrismException::providerRequestError($model, $e);
        }

        match ($e->response->getStatusCode()) {
            413 => throw PrismRequestTooLargeException::make(self::class),
            429 => throw PrismRateLimitedException::make([]),
            529 => throw PrismProviderOverloadedException::make(self::class),
            default => throw PrismException::providerRequestError($model, $e),
        };
    }
}
