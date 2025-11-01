<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Contracts\View\View;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Media\Video;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

trait HasPrompts
{
    protected ?string $prompt = null;

    /**
     * @var array<int, Audio|Text|Image|Media|Document|Video>
     */
    protected array $additionalContent = [];

    /**
     * @var SystemMessage[]
     */
    protected array $systemPrompts = [];

    /**
     * @param  array<int, Audio|Text|Image|Media|Document|Video>  $additionalContent
     */
    public function withPrompt(string|View $prompt, array $additionalContent = []): self
    {
        if (is_string($prompt)) {
            $this->prompt = $prompt;
        } else {
            $renderedPrompt = $prompt->render();
            $this->prompt = $this->filterLivewireMorphMarkers($renderedPrompt);
        }

        $this->additionalContent = $additionalContent;

        return $this;
    }

    public function withSystemPrompt(string|View|SystemMessage $message): self
    {
        if ($message instanceof SystemMessage) {
            $this->systemPrompts[] = $message;

            return $this;
        }

        if (is_string($message)) {
            $this->systemPrompts[] = new SystemMessage($message);
        } else {
            $renderedMessage = $message->render();
            $filteredMessage = $this->filterLivewireMorphMarkers($renderedMessage);
            $this->systemPrompts[] = new SystemMessage($filteredMessage);
        }

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

    protected function filterLivewireMorphMarkers(string $content): string
    {
        return $this->applyPromptFilters($content);
    }

    protected function applyPromptFilters(string $content): string
    {
        $filters = [
            '<!--[if BLOCK]><![endif]-->' => '',
            '<!--[if ENDBLOCK]><![endif]-->' => '',
        ];

        $filtered = str($content);

        foreach ($filters as $search => $replace) {
            $filtered = $filtered->replace($search, $replace);
        }

        return $filtered->toString();
    }
}
