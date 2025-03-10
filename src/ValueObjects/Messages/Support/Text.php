<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages\Support;

readonly class Text
{
    public function __construct(public string $text) {}
}
