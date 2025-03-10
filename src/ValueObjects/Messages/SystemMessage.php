<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages;

use Prism\Prism\Concerns\HasProviderMeta;
use Prism\Prism\Contracts\Message;

class SystemMessage implements Message
{
    use HasProviderMeta;

    public function __construct(
        public readonly string $content
    ) {}
}
