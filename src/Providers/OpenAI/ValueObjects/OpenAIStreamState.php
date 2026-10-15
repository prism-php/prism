<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\ValueObjects;

use Prism\Prism\Providers\OpenAI\Support\OutputItems;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\ValueObjects\Usage;

class OpenAIStreamState extends StreamState
{
    /** @var array<int, array<string, mixed>> */
    protected array $outputItems = [];

    /** @var array<string, string> */
    protected array $refusalParts = [];

    /** @var array<string, mixed> */
    protected array $terminalResponse = [];

    protected ?Usage $stepUsage = null;

    /**
     * Completed output items from response.output_item.done, keyed by output index.
     *
     * @param  array<string, mixed>  $item
     */
    public function addOutputItem(int $outputIndex, array $item): self
    {
        $this->outputItems[$outputIndex] = $item;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function outputItems(): array
    {
        $items = $this->outputItems;

        ksort($items);

        return array_values($items);
    }

    public function appendRefusal(string $partKey, string $delta): self
    {
        $this->refusalParts[$partKey] = ($this->refusalParts[$partKey] ?? '').$delta;

        return $this;
    }

    public function withRefusal(string $partKey, string $refusal): self
    {
        $this->refusalParts[$partKey] = $refusal;

        return $this;
    }

    public function refusal(): ?string
    {
        if ($this->refusalParts !== []) {
            return implode('', $this->refusalParts);
        }

        return OutputItems::refusal($this->outputItems());
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function withTerminalResponse(array $response): self
    {
        $this->terminalResponse = $response;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function terminalResponse(): array
    {
        return $this->terminalResponse;
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

    /**
     * Completed function calls collected for this step, in output order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function completedToolCalls(): array
    {
        $toolCalls = array_filter(
            $this->toolCalls(),
            fn (array $toolCall): bool => ($toolCall['complete'] ?? false) === true
        );

        ksort($toolCalls);

        return $toolCalls;
    }

    #[\Override]
    public function reset(): self
    {
        parent::reset();

        $this->finishReason = null;
        $this->outputItems = [];
        $this->refusalParts = [];
        $this->terminalResponse = [];
        $this->stepUsage = null;

        return $this;
    }
}
