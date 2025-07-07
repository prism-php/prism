<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Concerns;

use Prism\Prism\Exceptions\PrismException;

trait ValidatesResponses
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateResponse(array $data): void
    {
        if ($data === []) {
            throw PrismException::providerResponseError('OpenRouter Error: Empty response');
        }

        if (data_get($data, 'error')) {
            $this->handleOpenRouterError($data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleOpenRouterError(array $data): void
    {
        $error = data_get($data, 'error', []);
        $code = data_get($error, 'code', 'unknown');
        $message = data_get($error, 'message', 'Unknown error');
        $metadata = data_get($error, 'metadata', []);

        if ($code === 403 && isset($metadata['reasons'])) {
            throw PrismException::providerResponseError(sprintf(
                'OpenRouter Moderation Error: %s. Flagged input: %s',
                $message,
                data_get($metadata, 'flagged_input', 'N/A')
            ));
        }

        if (isset($metadata['provider_name'])) {
            throw PrismException::providerResponseError(sprintf(
                'OpenRouter Provider Error (%s): %s',
                data_get($metadata, 'provider_name'),
                $message
            ));
        }

        throw PrismException::providerResponseError(sprintf(
            'OpenRouter Error [%s]: %s',
            $code,
            $message
        ));
    }
}
