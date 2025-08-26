<?php

declare(strict_types=1);

namespace Prism\Prism\File;

use Prism\Prism\Contracts\Message;

class Response implements Message
{
    /**
     * @param  array<string, mixed>  $providerSpecificData
     */
    public function __construct(
        public ?string $id,
        public ?string $fileName,
        public ?int $bytes,
        public ?string $createdAt,
        public array $providerSpecificData = []
    ) {}
}
