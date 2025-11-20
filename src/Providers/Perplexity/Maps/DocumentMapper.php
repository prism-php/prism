<?php

namespace Prism\Prism\Providers\Perplexity\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;

class DocumentMapper extends ProviderMediaMapper
{
    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        $fileName = null;

        if ($this->media->isUrl()) {
            $url = $this->media->url();
        } else {
            $url = "data:{$this->media->mimeType()};base64,{$this->media->base64()}";
            $fileName = $this->media->fileName();
        }

        $payload = [
            'type' => 'file_url',
            'file_url' => [
                'url' => $url,
            ],
            'file_name' => $fileName,
        ];

        return array_filter($payload);
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
