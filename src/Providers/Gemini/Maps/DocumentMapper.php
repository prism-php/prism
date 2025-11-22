<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Providers\Gemini\Support\MediaUrlDetector;
use Prism\Prism\ValueObjects\Media\Document;

/**
 * @property Document $media
 */
class DocumentMapper extends ProviderMediaMapper
{
    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        $url = $this->media->url();

        if ($this->media->isUrl() && $url !== null && MediaUrlDetector::shouldPassAsFileUri($url)) {
            return [
                'file_data' => [
                    'file_uri' => $url,
                ],
            ];
        }

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
