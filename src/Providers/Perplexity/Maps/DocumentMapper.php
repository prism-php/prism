<?php

namespace Prism\Prism\Providers\Perplexity\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;

class DocumentMapper extends ProviderMediaMapper
{
    public const SUPPORTED_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/rtf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        'text/rtf',
    ];

    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        $url = $this->media->isUrl() ? $this->media->url() : $this->media->base64();

        $payload = [
            'type' => 'file_url',
            'file_url' => [
                'url' => $url,
            ],
            'file_name' => $this->media->fileName(),
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

        if ($this->media->hasMimeType() && $this->media->hasRawContent()) {
            return in_array($this->media->mimeType(), self::SUPPORTED_MIME_TYPES, true);
        }

        return false;
    }
}
