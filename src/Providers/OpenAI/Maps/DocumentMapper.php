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
        return [
            'type' => 'input_file',
            'filename' => $this->media->documentTitle(),
            'file_data' => sprintf('data:%s;base64,%s', $this->media->mimeType(), $this->media->base64()),
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
