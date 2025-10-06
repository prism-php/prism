<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\File;

class ListResponse
{
    /**
     * @param  array<string, mixed>  $providerSpecificData
     */
    public function __construct(
        public readonly array $providerSpecificData,
    ) {}
}
