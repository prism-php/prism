<?php

namespace Prism\Prism\Providers\Perplexity\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;

class ImageMapper extends ProviderMediaMapper
{
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
        return $this->media->hasRawContent();
    }
}
