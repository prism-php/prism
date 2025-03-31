<?php

namespace Prism\Prism\Concerns;

trait HasModelOptions
{
    /** @var array<string, mixed> */
    protected array $modelOptions = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function withOptions(array $options): self
    {
        $this->modelOptions = array_merge($this->modelOptions, $options);

        return $this;
    }
}
