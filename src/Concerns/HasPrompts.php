<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Contracts\View\View;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

trait HasPrompts
{
    protected ?string $prompt = null;

    /**
     * @var array<int, \Prism\Prism\ValueObjects\Text|\Prism\Prism\ValueObjects\Image|\Prism\Prism\ValueObjects\Document|\Prism\Prism\ValueObjects\OpenAIFile>
     */
    protected $additionalPromptContent = [];

    /**
     * @var SystemMessage[]
     */
    protected array $systemPrompts = [];

    /**
     * @param  array<int, \Prism\Prism\ValueObjects\Text|\Prism\Prism\ValueObjects\Image|\Prism\Prism\ValueObjects\Document|\Prism\Prism\ValueObjects\OpenAIFile>  $additionalContent
     */
    public function withPrompt(string|View $prompt, $additionalContent = []): self
    {
        $this->prompt = is_string($prompt) ? $prompt : $prompt->render();

        $this->additionalPromptContent = $additionalContent;

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
        $this->systemPrompts = $messages;

        return $this;
    }
}
