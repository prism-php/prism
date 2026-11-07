<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\Tool;
use Prism\Prism\Tools\LaravelMcpTool;

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
        $this->tools = array_values(array_filter(
            $tools,
            fn (Tool $tool): bool => ! $tool instanceof LaravelMcpTool || $tool->eligibleForRegistration()
        ));

        return $this;
    }

    public function withoutToolErrorHandling(): self
    {
        $this->toolErrorHandlingEnabled = false;

        return $this;
    }
}
