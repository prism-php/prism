<?php

declare(strict_types=1);

namespace Prism\Prism\File;

class ListResponse
{
    /**
     * @param  array<string, mixed>  $providerSpecificData
     */
    public function __construct(
        public readonly array $providerSpecificData,
    ) {}
}
