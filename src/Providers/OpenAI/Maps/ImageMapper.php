<?php

namespace Prism\Prism\Providers\OpenAI\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;

/**
 * @property Image $media
 */
class ImageMapper extends ProviderMediaMapper
{
    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'type' => 'input_image',
        ];

        if ($this->media->isFileId()) {
            $payload['file_id'] = $this->media->fileId();
        } elseif ($this->media->isUrl()) {
            $payload['image_url'] = $this->media->url();
        } else {
            $payload['image_url'] = sprintf(
                'data:%s;base64,%s',
                $this->media->mimeType(),
                $this->media->base64()
            );
        }

        if ($this->media->providerOptions('detail')) {
            $payload['detail'] = $this->media->providerOptions('detail');
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
