<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public string $toolId,
        public string $toolName,
        public array $args,
        public int|float|string|array|null $result,
        public ?string $toolCallId = null,
    ) {}
}
