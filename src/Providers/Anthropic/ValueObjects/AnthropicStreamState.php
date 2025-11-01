<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\ValueObjects;

use Prism\Prism\Streaming\StreamState;

class AnthropicStreamState extends StreamState
{
    protected string $currentThinkingSignature = '';

    /** @var array<int, array<string, mixed>> */
    protected array $serverToolCalls = [];

    /** @var array<int, array<string, mixed>> */
    protected array $webSearchToolResults = [];

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
     * @param  array<string, mixed>  $toolCall
     */
    public function addServerToolCall(array $toolCall): self
    {
        $this->serverToolCalls[] = $toolCall;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function serverToolCalls(): array
    {
        return $this->serverToolCalls;
    }

    public function hasServerToolCalls(): bool
    {
        return $this->serverToolCalls !== [];
    }

    public function appendServerToolCallInput(int $index, string $input): self
    {
        if (! isset($this->serverToolCalls[$index]['input'])) {
            $this->serverToolCalls[$index]['input'] = '';
        }

        $this->serverToolCalls[$index]['input'] .= $input;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $toolResult
     */
    public function addWebSearchToolResult(array $toolResult): self
    {
        $this->webSearchToolResults[] = $toolResult;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function webSearchToolResults(): array
    {
        return $this->webSearchToolResults;
    }

    public function hasWebSearchToolResults(): bool
    {
        return $this->webSearchToolResults !== [];
    }

    public function reset(): self
    {
        parent::reset();
        $this->currentThinkingSignature = '';
        $this->serverToolCalls = [];
        $this->webSearchToolResults = [];

        return $this;
    }

    public function resetTextState(): self
    {
        parent::resetTextState();
        $this->currentThinkingSignature = '';
        $this->serverToolCalls = [];
        $this->webSearchToolResults = [];

        return $this;
    }
}
