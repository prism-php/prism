<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Concerns;

use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;

trait HandleResponseError
{
    protected Response $httpResponse;

    protected function handleResponseError(): void
    {
        $data = $this->httpResponse->json();

        if (! $data || data_get($data, 'error')) {
            throw PrismException::providerResponseError(vsprintf(
                'Gemini Error: [%s] %s',
                [
                    data_get($data, 'error.code', 'unknown'),
                    data_get($data, 'error.message', 'unknown'),
                ]
            ));
        }
    }
}
