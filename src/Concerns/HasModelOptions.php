<?php

namespace Prism\Prism\Concerns;

use Prism\Prism\Text\PendingRequest;

trait HasModelOptions
{
    /** @var array<string, array<string, mixed>> */
    protected array $modelOptions = [];

    /**
     * @return HasModelOptions|\Prism\Prism\Structured\PendingRequest|PendingRequest
     */
    public function withOptions(array $options): self
    {
        $this->modelOptions = array_merge($this->modelOptions, $options);

        return $this;
    }
}
