<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Contracts\View\View;
use Prism\Prism\ValueObjects\Messages\Support\Audio;
use Prism\Prism\ValueObjects\Messages\Support\Document;
use Prism\Prism\ValueObjects\Messages\Support\Image;
use Prism\Prism\ValueObjects\Messages\Support\Media;
use Prism\Prism\ValueObjects\Messages\Support\OpenAIFile;
use Prism\Prism\ValueObjects\Messages\Support\Text;
use Prism\Prism\ValueObjects\Messages\Support\Video;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

trait HasPrompts
{
    protected ?string $prompt = null;

    /**
     * @var array<int, Audio|Text|Image|Media|Document|OpenAIFile|Video>
     */
    protected array $additionalContent = [];

    /**
     * @var SystemMessage[]
     */
    protected array $systemPrompts = [];

    /**
     * @param  array<int, Audio|Text|Image|Media|Document|OpenAIFile|Video>  $additionalContent
     */
    public function withPrompt(string|View $prompt, array $additionalContent = []): self
    {
        $this->prompt = is_string($prompt) ? $prompt : $prompt->render();
        $this->additionalContent = $additionalContent;

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
