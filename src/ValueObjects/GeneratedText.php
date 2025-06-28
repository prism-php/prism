<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

readonly class GeneratedText
{
    public function __construct(
        public string $text,
    ) {}

    public function hasText(): bool
    {
        return $this->text !== '' && $this->text !== '0';
    }
}
