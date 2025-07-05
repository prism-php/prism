<?php

declare(strict_types=1);

namespace Prism\Prism\Audio;

use Prism\Prism\ValueObjects\Usage;

readonly class TextResponse
{
    public function __construct(
        public string $text,
        public ?Usage $usage = null,
        /** @var array<string,mixed> */
        public array $additionalContent = []
    ) {}
}
