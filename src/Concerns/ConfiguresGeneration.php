<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

trait ConfiguresGeneration
{
    protected int $maxSteps = 1;

    public function withMaxSteps(int $steps): self
    {
        $this->maxSteps = $steps;

        return $this;
    }
}
