<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Contracts\View\View;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

trait HasPrompts
{
    protected ?string $prompt = null;

    /**
     * @var SystemMessage[]
     */
    protected array $systemPrompts = [];

    public function withPrompt(string|View $prompt): self
    {
        $this->prompt = is_string($prompt) ? $prompt : $prompt->render();

        return $this;
    }

    public function withSystemPrompt(string|View|SystemMessage $message): self
    {
        if ($message instanceof SystemMessage) {
            $this->systemPrompts[] = $message;

            return $this;
        }

        $this->systemPrompts[] = new SystemMessage(is_string($message) ? $message : $message->render());

        return $this;
    }

    /**
     * @param  SystemMessage[]  $messages
     */
    public function withSystemPrompts(array $messages): self
    {
        $this->systemPrompts = array_merge($this->systemPrompts, $messages);

        return $this;
    }
}
