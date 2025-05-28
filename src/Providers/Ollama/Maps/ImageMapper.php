<?php

namespace Prism\Prism\Providers\Ollama\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\Support\Image;

/**
 * @property Image $media
 */
class ImageMapper extends ProviderMediaMapper
{
    public function toPayload(): mixed
    {
        return $this->media->base64();
    }

    protected function provider(): string|Provider
    {
        return Provider::Ollama;
    }

    protected function validateMedia(): bool
    {
        if ($this->media->isUrl()) {
            return true;
        }

        return $this->media->hasRawContent();
    }
}
