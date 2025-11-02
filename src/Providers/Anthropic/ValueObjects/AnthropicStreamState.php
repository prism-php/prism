<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\ValueObjects;

use Prism\Prism\Streaming\StreamState;

class AnthropicStreamState extends StreamState
{
    protected string $currentThinkingSignature = '';

    /** @var array<int, array<string, mixed>> */
    protected array $providerToolCalls = [];

    public function appendThinkingSignature(string $signature): self
    {
        $this->currentThinkingSignature .= $signature;

        return $this;
    }

    public function currentThinkingSignature(): string
    {
        return $this->currentThinkingSignature;
    }

    /**
     * @param  array<string, mixed>  $providerToolCall
     */
    public function addProviderToolCall(int $index, array $providerToolCall): self
    {
        $this->providerToolCalls[$index] = $providerToolCall;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function providerToolCalls(): array
    {
        return $this->providerToolCalls;
    }

    public function appendProviderToolCallInput(int $index, string $input): self
    {
        if (! isset($this->providerToolCalls[$index])) {
            $this->providerToolCalls[$index] = ['input' => ''];
        }

        $this->providerToolCalls[$index]['input'] .= $input;

        return $this;
    }

    public function reset(): self
    {
        parent::reset();
        $this->currentThinkingSignature = '';
        $this->providerToolCalls = [];

        return $this;
    }

    public function resetTextState(): self
    {
        parent::resetTextState();
        $this->currentThinkingSignature = '';
        $this->providerToolCalls = [];

        return $this;
    }
}
