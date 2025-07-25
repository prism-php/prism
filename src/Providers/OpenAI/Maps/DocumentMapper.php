<?php

namespace Prism\Prism\Providers\OpenAI\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
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
        $payload = [
            'type' => 'input_file',
        ];

        if ($this->media->isFileId()) {
            $payload['file_id'] = $this->media->fileId();
        } elseif ($this->media->isUrl()) {
            $payload['file_url'] = $this->media->url();
        } else {
            $payload['filename'] = $this->media->documentTitle();
            $payload['file_data'] = sprintf('data:%s;base64,%s', $this->media->mimeType(), $this->media->base64());
        }

        return array_filter($payload);
    }

    protected function provider(): string|Provider
    {
        return Provider::OpenAI;
    }

    protected function validateMedia(): bool
    {
        if ($this->media->isFileId()) {
            return true;
        }

        if ($this->media->isUrl()) {
            return true;
        }

        return $this->media->hasRawContent();
    }
}
