<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Processors;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;

class ErrorProcessor
{
    /**
     * @param  array<string, mixed>  $chunk
     */
    public function process(array $chunk): void
    {
        if (data_get($chunk, 'error.type') === 'overloaded_error') {
            throw new PrismProviderOverloadedException('Anthropic');
        }

        throw PrismException::providerResponseError(vsprintf(
            'Anthropic Error: [%s] %s',
            [
                data_get($chunk, 'error.type', 'unknown'),
                data_get($chunk, 'error.message'),
            ]
        ));
    }
}
