<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Media;

readonly class Text
{
    public function __construct(public string $text) {}
}
