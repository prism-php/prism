<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages;

use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Contracts\Message;

class SystemMessage implements Message
{
    use HasProviderOptions;

    public function __construct(
        public readonly string $content
    ) {}
}
