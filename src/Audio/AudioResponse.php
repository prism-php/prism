<?php

declare(strict_types=1);

namespace Prism\Prism\Audio;

use Prism\Prism\ValueObjects\GeneratedAudio;

readonly class AudioResponse
{
    public function __construct(
        public GeneratedAudio $audio,
        /** @var array<string,mixed> */
        public array $additionalContent = []
    ) {}
}
