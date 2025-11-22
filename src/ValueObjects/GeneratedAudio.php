<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

class GeneratedAudio extends Media\Media
{
    public function __construct(?string $base64 = null, public ?string $type = null)
    {
        parent::__construct(null, $base64, $type);
    }
}
