<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Tool;

trait ConfiguresTools
{
    protected string|ToolChoice|null $toolChoice = null;

    protected ?int $toolChoiceAutoAfterSteps = null;

    public function withToolChoice(string|ToolChoice|Tool $toolChoice, ?int $toolChoiceAutoAfterSteps = null): self
    {
        $this->toolChoice = $toolChoice instanceof Tool
            ? $toolChoice->name()
            : $toolChoice;

        $this->toolChoiceAutoAfterSteps = $toolChoiceAutoAfterSteps;

        return $this;
    }
}
