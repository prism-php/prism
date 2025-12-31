<?php

namespace Prism\Prism\Providers\Perplexity\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;

class ImageMapper extends ProviderMediaMapper
{
    public const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        $url = $this->media->isUrl() ? $this->media->url() : "data:{$this->media->mimeType()};base64,{$this->media->base64()}";

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => $url,
            ],
        ];
    }

    protected function provider(): string|Provider
    {
        return Provider::Perplexity;
    }

    protected function validateMedia(): bool
    {
        if ($this->media->isUrl()) {
            return true;
        }

        if ($this->media->hasMimeType() && $this->media->hasRawContent()) {
            return in_array($this->media->mimeType(), self::SUPPORTED_MIME_TYPES, true);
        }

        return false;
    }
}
