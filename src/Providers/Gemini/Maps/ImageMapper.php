<?php

namespace Prism\Prism\Providers\Gemini\Maps;

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
            'inline_data' => [
                'mime_type' => $this->media->mimeType(),
                'data' => $this->media->base64(),
            ],
        ];
    }

    protected function provider(): string|Provider
    {
        return Provider::Gemini;
    }

    protected function validateMedia(): bool
    {
        if ($this->media->isUrl()) {
            return true;
        }

        return $this->media->hasRawContent();
    }
}
