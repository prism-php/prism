<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages;

use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\OpenAIFile;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Media\Video;

class UserMessage implements Message
{
    use HasProviderOptions;

    /**
     * @param  array<int, Audio|Text|Image|Media|Document|OpenAIFile>  $additionalContent
     * @param  array<string, mixed>  $additionalAttributes
     */
    public function __construct(
        public readonly string $content,
        public array $additionalContent = [],
        public readonly array $additionalAttributes = [],
    ) {
        $this->additionalContent[] = new Text($content);
    }

    public function text(): string
    {
        $result = '';

        foreach ($this->additionalContent as $content) {
            if ($content instanceof Text) {
                $result .= $content->text;
            }
        }

        return $result;
    }

    /**
     * @return Image[]
     */
    public function images(): array
    {
        return collect($this->additionalContent)
            ->where(fn ($part): bool => $part instanceof Image)
            ->toArray();
    }

    /**
     * @return array<int, Audio|Video|Media>
     */
    public function media(): array
    {
        return collect($this->additionalContent)
            ->filter(fn ($part): bool => $part instanceof Audio || $part instanceof Video || $part instanceof Media)
            ->toArray();
    }

    /**
     * Note: Prism currently only supports Documents with Anthropic.
     *
     * @return Document[]
     */
    public function documents(): array
    {
        return collect($this->additionalContent)
            ->where(fn ($part): bool => $part instanceof Document)
            ->toArray();
    }

    /**
     * Note: Prism currently only supports previously uploaded Files with OpenAI.
     *
     * @return OpenAIFile[]
     */
    public function files(): array
    {
        return collect($this->additionalContent)
            ->where(fn ($part): bool => $part instanceof OpenAIFile)
            ->toArray();
    }

    /**
     * @return Audio[]
     */
    public function audios(): array
    {
        return collect($this->additionalContent)
            ->where(fn ($part): bool => $part instanceof Audio)
            ->toArray();
    }
}
