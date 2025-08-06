<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ElevenLabs\Concerns;

use Illuminate\Http\Client\Response;

trait ValidatesResponse
{
    protected function validateResponse(Response $response): void
    {
        // TODO: Implement ElevenLabs-specific response validation
        // - Check for API errors in response body
        // - Handle rate limiting headers
        // - Validate expected response structure
        // - Throw appropriate Prism exceptions for different error types

        if (! $response->successful()) {
            // TODO: Parse ElevenLabs error format and throw appropriate exceptions
            // ElevenLabs may return errors in different formats for different endpoints
        }
    }
}
