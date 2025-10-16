<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;

/**
 * NOTE: mirrored from Gemini's AudioVideoMapper so future refactors can consolidate.
 */
class VideoMapper extends ProviderMediaMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'type' => 'input_video',
            'input_video' => [
                'data' => $this->media->base64(),
                'format' => $this->determineFormat(),
            ],
        ];
    }

    protected function provider(): string|Provider
    {
        return Provider::OpenRouter;
    }

    protected function validateMedia(): bool
    {
        return $this->media->hasRawContent();
    }

    protected function determineFormat(): string
    {
        $mimeType = $this->media->mimeType();

        return match ($mimeType) {
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/ogg', 'video/ogv' => 'ogg',
            default => 'mp4',
        };
    }
}
