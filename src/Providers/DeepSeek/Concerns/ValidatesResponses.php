<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\DeepSeek\Concerns;

use Prism\Prism\Exceptions\PrismException;

trait ValidatesResponses
{
    protected function validateResponse(): void
    {
        if ($this->httpResponse->json() === []) {
            throw PrismException::providerResponseError('DeepSeek Error: Empty response');
        }
    }
}
