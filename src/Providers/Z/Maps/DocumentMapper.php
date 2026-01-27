<?php

namespace Prism\Prism\Providers\Z\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Providers\Z\Enums\DocumentType;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Media;

/**
 * @property Image $media
 */
class DocumentMapper extends ProviderMediaMapper
{
    public function __construct(
        Media $media,
        public readonly DocumentType $type,
    ) {
        parent::__construct($media);
    }

    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return [
            'type' => $this->type->value,
            $this->type->value => [
                'url' => $this->media->url(),
            ],
        ];
    }

    protected function provider(): string|Provider
    {
        return Provider::Z;
    }

    protected function validateMedia(): bool
    {
        return $this->media->isUrl();
    }
}
