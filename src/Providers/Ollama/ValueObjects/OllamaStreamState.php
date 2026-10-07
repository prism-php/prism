<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\ValueObjects;

use Prism\Prism\Streaming\StreamState;
use Prism\Prism\ValueObjects\Usage;

class OllamaStreamState extends StreamState
{
    protected int $promptTokens = 0;

    protected int $completionTokens = 0;

    public function addPromptTokens(int $tokens): self
    {
        $this->promptTokens += $tokens;

        return $this->syncUsage();
    }

    public function addCompletionTokens(int $tokens): self
    {
        $this->completionTokens += $tokens;

        return $this->syncUsage();
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
