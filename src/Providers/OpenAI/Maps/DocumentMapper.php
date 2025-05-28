<?php

namespace Prism\Prism\Providers\OpenAI\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\Support\Document;

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
            'type' => 'file',
            'file' => Arr::whereNotNull([
                'file_data' => sprintf('data:%s;base64,%s', $this->media->mimeType(), $this->media->base64()),
                'filename' => $this->media->documentTitle(),
            ]),
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
