<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\Concerns;

use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;

trait HandleResponseError
{
    protected Response $httpResponse;

    protected function handleResponseError(): void
    {
        $data = $this->httpResponse->json();

        if (! $data || data_get($data, 'error')) {
            throw PrismException::providerResponseError(sprintf(
                'Ollama Error: %s',
                data_get($data, 'error', 'unknown'),
            ));
        }
    }
}
