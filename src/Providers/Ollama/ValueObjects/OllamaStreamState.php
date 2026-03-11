<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\ValueObjects;

use Prism\Prism\Streaming\StreamState;

class OllamaStreamState extends StreamState
{
    protected int $promptTokens = 0;

    protected int $completionTokens = 0;

    public function addPromptTokens(int $tokens): self
    {
        $this->promptTokens += $tokens;

        return $this;
    }

    public function addCompletionTokens(int $tokens): self
    {
        $this->completionTokens += $tokens;

        return $this;
    }

    public function promptTokens(): int
    {
        return $this->promptTokens;
    }

    public function completionTokens(): int
    {
        return $this->completionTokens;
    }

    public function reset(): self
    {
        parent::reset();
        // Note: Token counts are intentionally NOT reset here.
        // They accumulate across tool-call turns to provide total usage.

        return $this;
    }
}
