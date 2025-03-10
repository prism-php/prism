<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Tool;

trait ConfiguresTools
{
    protected string|ToolChoice|null $toolChoice = null;

    public function withToolChoice(string|ToolChoice|Tool $toolChoice): self
    {
        $this->toolChoice = $toolChoice instanceof Tool
            ? $toolChoice->name()
            : $toolChoice;

        return $this;
    }
}
