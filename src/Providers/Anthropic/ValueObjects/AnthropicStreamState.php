<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\ValueObjects;

use Prism\Prism\Streaming\StreamState;
use Prism\Prism\ValueObjects\Usage;

class AnthropicStreamState extends StreamState
{
    protected string $currentThinkingSignature = '';

    /** @var array<int, array<string, mixed>> */
    protected array $providerToolCalls = [];

    /** @var array<int, array<string, mixed>> */
    protected array $providerToolResults = [];

    /** @var array<int, array<string, mixed>> */
    protected array $contentBlocks = [];

    /** @var array<int, string> */
    protected array $contentBlockInputs = [];

    /** @var array<int, true> */
    protected array $completedContentBlocks = [];

    protected ?string $stopReason = null;

    /** @var array<string, mixed>|null */
    protected ?array $stopDetails = null;

    /** @var array<int, array<string, mixed>>|null */
    protected ?array $usageIterations = null;

    protected ?Usage $stepUsage = null;

    /** The step's `message_stop` arrived: Anthropic finished sending the message. */
    protected bool $messageStopped = false;

    /** The step's `message_delta` carried its final `output_tokens`. */
    protected bool $finalUsageReported = false;

    /**
     * Some step of this turn ended without its final usage, so the turn's
     * total understates what was generated. Kept across steps on purpose.
     */
    protected bool $turnUsageIncomplete = false;

    public function markMessageStopped(): self
    {
        $this->messageStopped = true;

        return $this;
    }

    public function messageStopped(): bool
    {
        return $this->messageStopped;
    }

    public function markFinalUsageReported(): self
    {
        $this->finalUsageReported = true;

        return $this;
    }

    public function finalUsageReported(): bool
    {
        return $this->finalUsageReported;
    }

    public function markTurnUsageIncomplete(): self
    {
        $this->turnUsageIncomplete = true;

        return $this;
    }

    public function turnUsageIncomplete(): bool
    {
        return $this->turnUsageIncomplete;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    public function startContentBlock(int $index, array $block): self
    {
        $this->contentBlocks[$index] = $block;

        return $this;
    }

    public function appendContentBlockField(int $index, string $field, string $value): self
    {
        if (isset($this->contentBlocks[$index])) {
            $this->contentBlocks[$index][$field] = ($this->contentBlocks[$index][$field] ?? '').$value;
        }

        return $this;
    }

    public function appendContentBlockInput(int $index, string $partialJson): self
    {
        $this->contentBlockInputs[$index] = ($this->contentBlockInputs[$index] ?? '').$partialJson;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $citation
     */
    public function appendContentBlockCitation(int $index, array $citation): self
    {
        if (isset($this->contentBlocks[$index])) {
            $this->contentBlocks[$index]['citations'] = [
                ...($this->contentBlocks[$index]['citations'] ?? []),
                $citation,
            ];
        }

        return $this;
    }

    public function completeContentBlock(int $index): self
    {
        $this->completedContentBlocks[$index] = true;

        return $this;
    }

    public function isContentBlockComplete(int $index): bool
    {
        return isset($this->completedContentBlocks[$index]);
    }

    /**
     * The step's content blocks in stream order, with streamed tool inputs
     * decoded, keyed by their stream index.
     *
     * @return array<int, array<string, mixed>>
     */
    public function contentBlocks(): array
    {
        $blocks = $this->contentBlocks;
        ksort($blocks);

        foreach ($this->contentBlockInputs as $index => $json) {
            if (isset($blocks[$index]) && $json !== '') {
                $blocks[$index]['input'] = json_decode($json, true) ?? $json;
            }
        }

        return $blocks;
    }

    /**
     * The content blocks that can be sent back to Anthropic: a block the
     * stream never closed (an unfinished tool input, a thinking block without
     * its signature) is left out; partial text is kept.
     *
     * @return array<int, array<string, mixed>>
     */
    public function replayableContentBlocks(): array
    {
        return array_filter(
            $this->contentBlocks(),
            fn (array $block, int $index): bool => ($block['type'] ?? null) === 'text' || $this->isContentBlockComplete($index),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param  array<string, mixed>|null  $stopDetails
     */
    public function withStopReason(?string $stopReason, ?array $stopDetails): self
    {
        $this->stopReason = $stopReason;
        $this->stopDetails = $stopDetails;

        return $this;
    }

    public function stopReason(): ?string
    {
        return $this->stopReason;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function stopDetails(): ?array
    {
        return $this->stopDetails;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $usageIterations
     */
    public function withUsageIterations(?array $usageIterations): self
    {
        $this->usageIterations = $usageIterations;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function usageIterations(): ?array
    {
        return $this->usageIterations;
    }

    public function withStepUsage(Usage $usage): self
    {
        $this->stepUsage = $usage;

        return $this;
    }

    public function stepUsage(): ?Usage
    {
        return $this->stepUsage;
    }

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

    public function hasProviderToolCalls(): bool
    {
        return $this->providerToolCalls !== [];
    }

    public function appendProviderToolCallInput(int $index, string $input): self
    {
        if (! isset($this->providerToolCalls[$index])) {
            $this->providerToolCalls[$index] = ['input' => ''];
        }

        $this->providerToolCalls[$index]['input'] .= $input;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $toolResult
     */
    public function addProviderToolResult(int $index, array $toolResult): self
    {
        $this->providerToolResults[$index] = $toolResult;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function providerToolResults(): array
    {
        return $this->providerToolResults;
    }

    public function hasProviderToolResults(): bool
    {
        return $this->providerToolResults !== [];
    }

    public function reset(): self
    {
        parent::reset();
        $this->currentThinkingSignature = '';
        $this->providerToolCalls = [];
        $this->providerToolResults = [];
        $this->contentBlocks = [];
        $this->contentBlockInputs = [];
        $this->completedContentBlocks = [];
        $this->stopReason = null;
        $this->stopDetails = null;
        $this->usageIterations = null;
        $this->stepUsage = null;
        $this->finishReason = null;
        $this->messageStopped = false;
        $this->finalUsageReported = false;

        return $this;
    }

    public function resetTextState(): self
    {
        parent::resetTextState();
        $this->currentThinkingSignature = '';
        $this->providerToolCalls = [];
        $this->providerToolResults = [];

        return $this;
    }
}
