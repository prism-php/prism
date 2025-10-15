<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\ValueObjects;

use Prism\Prism\Streaming\StreamState;

class AnthropicStreamState extends StreamState
{
    protected string $currentThinkingSignature = '';

    public function appendThinkingSignature(string $signature): self
    {
        $this->currentThinkingSignature .= $signature;

        return $this;
    }

    public function currentThinkingSignature(): string
    {
        return $this->currentThinkingSignature;
    }

    public function reset(): self
    {
        parent::reset();
        $this->currentThinkingSignature = '';

        return $this;
    }

    public function resetTextState(): self
    {
        parent::resetTextState();
        $this->currentThinkingSignature = '';

        return $this;
    }
}
