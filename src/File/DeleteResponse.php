<?php

declare(strict_types=1);

namespace Prism\Prism\File;

class DeleteResponse
{
    /**
     * @param  array<string, mixed>  $providerSpecificData
     */
    public function __construct(
        public readonly array $providerSpecificData
    ) {}
}
