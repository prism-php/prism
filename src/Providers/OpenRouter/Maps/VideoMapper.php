<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;

/**
 * Maps video media to OpenRouter's video_url format.
 *
 * @see https://openrouter.ai/docs/guides/overview/multimodal/videos
 */
class VideoMapper extends ProviderMediaMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        if ($this->media->isUrl()) {
            return [
                'type' => 'video_url',
                'video_url' => [
                    'url' => $this->media->url(),
                ],
            ];
        }

        $dataUrl = "data:{$this->media->mimeType()};base64,".$this->media->base64();

        return [
            'type' => 'video_url',
            'video_url' => [
                'url' => $dataUrl,
            ],
        ];
    }

    protected function provider(): string|Provider
    {
        return Provider::OpenRouter;
    }

    protected function validateMedia(): bool
    {
        if ($this->media->hasRawContent()) {
            return true;
        }

        return $this->media->isUrl();
    }
}
