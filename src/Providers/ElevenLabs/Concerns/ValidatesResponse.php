<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ElevenLabs\Concerns;

use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;

trait ValidatesResponse
{
    protected function validateResponse(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $body = $response->json() ?? [];
        $status = $response->status();

        $message = $body['detail']['message'] ?? $body['detail'] ?? $body['message'] ?? 'Unknown error from ElevenLabs API';

        if ($status === 429) {
            $retryAfter = $response->header('Retry-After') ? (int) $response->header('Retry-After') : null;
            throw PrismRateLimitedException::make([], $retryAfter);
        }

        throw PrismException::providerResponseError(
            vsprintf('ElevenLabs Error [%s]: %s', [$status, $message])
        );
    }
}
