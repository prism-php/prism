<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

readonly class Text
{
    public function __construct(public string $text) {}
}
