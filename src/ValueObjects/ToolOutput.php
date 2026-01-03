<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

readonly class ToolOutput
{
    /**
     * @param  array<int, Artifact>  $artifacts
     */
    public function __construct(
        public string $result,
        public array $artifacts = [],
    ) {}

    public function hasArtifacts(): bool
    {
        return $this->artifacts !== [];
    }
}
