<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\Tool;

trait HasTools
{
    /** @var array<int, Tool> */
    protected array $tools = [];

    protected bool $toolErrorHandlingEnabled = true;

    /**
     * @param  array<int, Tool>  $tools
     */
    public function withTools(array $tools): self
    {
        $this->tools = $tools;

        return $this;
    }

    public function withoutToolErrorHandling(): self
    {
        $this->toolErrorHandlingEnabled = false;

        return $this;
    }
}
