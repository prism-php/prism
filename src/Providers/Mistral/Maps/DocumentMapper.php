<?php

namespace Prism\Prism\Providers\Mistral\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Document;

/**
 * @property Document $media
 */
class DocumentMapper extends ProviderMediaMapper
{
    /**
     * @return array<string,string>
     */
    public function toPayload(): array
    {
        return Arr::whereNotNull([
            'type' => 'document_url',
            'document_url' => $this->media->url(),
            'document_name' => $this->media->documentTitle(),
        ]);
    }

    protected function provider(): string|Provider
    {
        return Provider::Mistral;
    }

    protected function validateMedia(): bool
    {
        return $this->media->isUrl();
    }
}
