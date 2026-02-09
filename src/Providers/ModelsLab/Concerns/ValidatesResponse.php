<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ModelsLab\Concerns;

use Prism\Prism\Exceptions\PrismException;

trait ValidatesResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateResponse(array $data): void
    {
        $error = $data['error'] ?? null;

        if ($error !== null) {
            $message = is_array($error)
                ? ($error['message'] ?? 'Unknown error from ModelsLab API')
                : (is_string($error) ? $error : 'Unknown error from ModelsLab API');

            throw PrismException::providerResponseError($message);
        }
    }
}
