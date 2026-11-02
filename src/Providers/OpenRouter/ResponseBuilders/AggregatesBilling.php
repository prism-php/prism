<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\ResponseBuilders;

use Prism\Prism\Providers\OpenRouter\ValueObjects\Billing;
use Prism\Prism\ValueObjects\Usage;

/** @phpstan-import-type Facts from Billing */
trait AggregatesBilling
{
    /** @return list<Facts> */
    protected function billingSteps(): array
    {
        $facts = [];
        foreach ($this->steps as $step) {
            $facts[] = Billing::fromFacts($step->additionalContent['openrouter_billing'] ?? null);
        }

        return $facts;
    }

    /** @return array<string, mixed> */
    protected function responseAdditionalContent(): array
    {
        return [...parent::responseAdditionalContent(), 'openrouter_billing' => Billing::combine($this->billingSteps())];
    }

    protected function calculateTotalUsage(): Usage
    {
        $usage = parent::calculateTotalUsage();

        return new Usage($usage->promptTokens, $usage->completionTokens, $usage->cacheWriteInputTokens,
            $usage->cacheReadInputTokens, $usage->thoughtTokens, Billing::totalCost($this->billingSteps()),
            $usage->cacheWrite5mInputTokens, $usage->cacheWrite1hInputTokens);
    }
}
