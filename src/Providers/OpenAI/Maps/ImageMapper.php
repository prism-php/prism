<?php

namespace Prism\Prism\Providers\OpenAI\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\Support\Image;

/**
 * @property Image $media
 */
class ImageMapper extends ProviderMediaMapper
{
    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return [
            'type' => 'input_image',
            'image_url' => $this->media->isUrl()
                ? $this->media->url()
                : sprintf('data:%s;base64,%s', $this->media->mimeType(), $this->media->base64()),
        ];
    }

    protected function provider(): string|Provider
    {
        return Provider::OpenAI;
    }

    protected function validateMedia(): bool
    {
        if ($this->media->isUrl()) {
            return true;
        }

        return $this->media->hasRawContent();
    }
}
