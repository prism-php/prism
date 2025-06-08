<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\XAI\Concerns;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Http\Response;

trait ValidatesResponses
{
    protected function validateResponse(Response $response): void
    {
        if ($response->status() === 429) {
            throw new PrismRateLimitedException([]);
        }

        $data = $response->json();

        if (! $data || data_get($data, 'error')) {
            throw PrismException::providerResponseError(vsprintf(
                'XAI Error:  [%s] %s',
                [
                    data_get($data, 'error.type', 'unknown'),
                    data_get($data, 'error.message', 'unknown'),
                ]
            ));
        }
    }
}
