<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

trait ConfiguresModels
{
    protected ?int $maxTokens = 2048;

    protected int|float|null $temperature = null;

    protected int|float|null $topP = null;

    /**
     * @var array<string, mixed>
     */
    protected ?array $metadata = null;

    public function withMaxTokens(?int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;

        return $this;
    }

    public function usingTemperature(int|float|null $temperature): self
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function usingTopP(int|float $topP): self
    {
        $this->topP = $topP;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }
}
