<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\FireworksAI\Maps;

use Prism\Prism\Providers\OpenAI\Maps\MessageMap as OpenAIMessageMap;
use Prism\Prism\ValueObjects\Messages\Support\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class MessageMap extends OpenAIMessageMap
{
    /**
     * Override to handle FireworksAI's document inlining feature
     */
    #[\Override]
    protected function mapUserMessage(UserMessage $message): void
    {
        $imageParts = array_map(fn (Image $image): array => [
            'type' => 'image_url',
            'image_url' => [
                'url' => $this->processImageUrl($image),
            ],
        ], $message->images());

        $documentParts = array_map(fn ($document): array => [
            'type' => 'text',
            'text' => is_array($document->document) ? implode("\n", $document->document) : $document->document,
        ], $message->documents());

        $this->mappedMessages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $message->text()],
                ...$imageParts,
                ...$documentParts,
            ],
        ];
    }

    /**
     * Process image URL to preserve FireworksAI's #transform=inline feature
     */
    protected function processImageUrl(Image $image): string
    {
        if ($image->isUrl()) {
            return $image->image;
        }

        return sprintf('data:%s;base64,%s', $image->mimeType, $image->image);
    }
}
