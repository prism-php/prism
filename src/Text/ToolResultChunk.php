<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

readonly class ToolResultChunk
{
    /**
     * @param  array<int, \Prism\Prism\ValueObjects\ToolResult>  $toolResults
     */
    public function __construct(
        public array $toolResults
    ) {}
}
