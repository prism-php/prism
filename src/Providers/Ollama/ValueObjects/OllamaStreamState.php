<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\ValueObjects;

use Prism\Prism\Streaming\StreamState;
use Prism\Prism\ValueObjects\Usage;

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

        return $this->syncUsage();
    }

    /**
     * @deprecated Prism no longer reads this counter. Use `StreamState::addUsage()` and `StreamState::usage()` instead.
     */
    public function addCompletionTokens(int $tokens): self
    {
        $this->completionTokens += $tokens;

        return $this->syncUsage();
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

    /**
     * Keep the base class's running usage in step with these counters.
     *
     * These fields count Ollama's `prompt_eval_count` and `eval_count`, and
     * nothing else read them: `usage()` stayed null, so `takeStepUsage()`
     * handed each StepFinishEvent nothing and a streamed step span carried no
     * token counts at all. Writing the same numbers where every other provider
     * puts them makes the per-step delta work here too.
     */
    protected function syncUsage(): self
    {
        $this->withUsage(new Usage(
            promptTokens: $this->promptTokens,
            completionTokens: $this->completionTokens,
        ));

        return $this;
    }
}
