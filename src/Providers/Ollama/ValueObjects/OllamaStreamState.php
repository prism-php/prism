<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\ValueObjects;

use Prism\Prism\Streaming\StreamState;

class OllamaStreamState extends StreamState
{
    protected int $promptTokens = 0;

    protected int $completionTokens = 0;

    /**
     * @deprecated Prism no longer reads this counter. Use `StreamState::addUsage()` and `StreamState::usage()` instead.
     */
    public function addPromptTokens(int $tokens): self
    {
        $this->promptTokens += $tokens;

        return $this;
    }

    /**
     * @deprecated Prism no longer reads this counter. Use `StreamState::addUsage()` and `StreamState::usage()` instead.
     */
    public function addCompletionTokens(int $tokens): self
    {
        $this->completionTokens += $tokens;

        return $this;
    }

    /**
     * @deprecated Prism no longer reads this counter. Use `StreamState::addUsage()` and `StreamState::usage()` instead.
     */
    public function promptTokens(): int
    {
        return $this->promptTokens;
    }

    /**
     * @deprecated Prism no longer reads this counter. Use `StreamState::addUsage()` and `StreamState::usage()` instead.
     */
    public function completionTokens(): int
    {
        return $this->completionTokens;
    }

    public function reset(): self
    {
        parent::reset();
        // The deprecated token counters are intentionally NOT reset here, so
        // they keep accumulating across tool-call turns as they always have.

        return $this;
    }
}
