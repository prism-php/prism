<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\ValueObjects;

use Prism\Prism\Streaming\StreamState;
use Prism\Prism\ValueObjects\Usage;

class OpenRouterStreamState extends StreamState
{
    /** @var list<array{is_byok: bool|null, router_cost: float|null, upstream_cost: float|null, complete: bool}> */
    protected array $billingSteps = [];

    /** @param array<string, mixed> $usage */
    public function recordBilling(array $usage): void
    {
        $this->billingSteps[] = Billing::fromUsage($usage);
    }

    /** @return array{is_byok: bool|null, router_cost: float|null, upstream_cost: float|null, complete: bool} */
    public function stepBilling(): array
    {
        return $this->billingSteps[array_key_last($this->billingSteps)] ?? Billing::fromUsage([]);
    }

    public function usage(): ?Usage
    {
        $usage = parent::usage();

        return $usage instanceof Usage ? new Usage($usage->promptTokens, $usage->completionTokens,
            $usage->cacheWriteInputTokens, $usage->cacheReadInputTokens, $usage->thoughtTokens,
            Billing::totalCost($this->billingSteps), $usage->cacheWrite5mInputTokens,
            $usage->cacheWrite1hInputTokens) : null;
    }

    /** @var array<int, array<string, mixed>> */
    protected array $reasoningDetails = [];

    /**
     * @param  array<string, mixed>  $detail
     */
    public function addReasoningDetail(array $detail): self
    {
        $this->reasoningDetails[] = $detail;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function reasoningDetails(): array
    {
        return $this->reasoningDetails;
    }

    public function reset(): self
    {
        parent::reset();
        $this->reasoningDetails = [];
        $this->billingSteps = [];

        return $this;
    }

    public function resetTextState(): self
    {
        parent::resetTextState();
        $this->reasoningDetails = [];

        return $this;
    }
}
