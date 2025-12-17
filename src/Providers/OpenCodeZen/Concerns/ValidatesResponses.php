<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenCodeZen\Concerns;

use Illuminate\Support\Arr;
use Prism\Prism\Exceptions\PrismException;

trait ValidatesResponses
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateResponse(array $data): void
    {
        if ($data === []) {
            throw PrismException::providerResponseError('OpenCodeZen Error: Empty response');
        }

        if (Arr::get($data, 'error')) {
            $this->handleOpenCodeZenError($data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleOpenCodeZenError(array $data): void
    {
        $error = Arr::get($data, 'error', []);
        $code = Arr::get($error, 'code', 'unknown');
        $message = Arr::get($error, 'message', 'Unknown error');

        throw PrismException::providerResponseError(sprintf(
            'OpenCodeZen Error [%s]: %s',
            $code,
            $message
        ));
    }
}
