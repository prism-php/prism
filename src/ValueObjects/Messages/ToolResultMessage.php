<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages;

use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\ToolResult;

class ToolResultMessage implements Message
{
    use HasProviderOptions;

    /**
     * @param  ToolResult[]  $toolResults
     */
    public function __construct(
        public readonly array $toolResults
    ) {}
}
