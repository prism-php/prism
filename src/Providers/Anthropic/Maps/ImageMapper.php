<?php

namespace Prism\Prism\Providers\Anthropic\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Media;

class ImageMapper extends ProviderMediaMapper
{
    /**
     * @param  Image  $media
     * @param  array<string, mixed>  $cacheControl
     */
    public function __construct(
        public readonly Media $media,
        public ?array $cacheControl = null,
    ) {
        $this->runValidation();
    }

    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'type' => 'image',
            'cache_control' => $this->cacheControl,
        ];

        if ($this->media->isUrl()) {
            $payload['source'] = [
                'type' => 'url',
                'url' => $this->media->url(),
            ];
        } else {
            $payload['source'] = [
                'type' => 'base64',
                'media_type' => $this->media->mimeType(),
                'data' => $this->media->base64(),
            ];
        }

        return array_filter($payload);
    }

    protected function provider(): string|Provider
    {
        return Provider::Anthropic;
    }

    protected function validateMedia(): bool
    {
        if ($this->media->isUrl()) {
            return true;
        }

        return $this->media->hasRawContent();
    }
}
