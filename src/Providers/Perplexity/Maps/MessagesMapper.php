<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Perplexity\Maps;

use Exception;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class MessagesMapper
{
    /**
     * @param  array<int, Message>  $messages
     */
    public function __construct(
        private array $messages,
    ) {}

    /**
     * @return array<int, mixed>
     *
     * @throws Exception
     */
    public function toPayload(): array
    {
        // Sort so that 'system' role messages come first in the messages list
        usort($this->messages, static function (Message $a, Message $b): int {
            $aIsSystem = ($a instanceof SystemMessage) ? 0 : 1;
            $bIsSystem = ($b instanceof SystemMessage) ? 0 : 1;

            return $aIsSystem <=> $bIsSystem;
        });

        return array_map(
            fn (Message $message): array => match ($message::class) {
                UserMessage::class => $this->mapUserMessage($message),
                AssistantMessage::class => $this->mapAssistantMessage($message),
                SystemMessage::class => $this->mapSystemMessage($message),
                default => throw new Exception('Could not map message type '.$message::class),
            },
            $this->messages
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapUserMessage(UserMessage $message): array
    {
        return [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $message->text(),
                ],
                ...$this->mapImageParts($message->images()),
                ...$this->mapDocumentParts($message->documents()),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapAssistantMessage(AssistantMessage $assistantMessage): array
    {
        return [
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'assistant',
                    'text' => $assistantMessage->content,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapSystemMessage(SystemMessage $systemMessage): array
    {
        return [
            'role' => 'system',
            'content' => [
                [
                    'type' => 'system',
                    'text' => $systemMessage->content,
                ],
            ],
        ];
    }

    /**
     * @param  Image[]  $parts
     * @return array<int, mixed>
     */
    protected function mapImageParts(array $parts): array
    {
        return array_map(static fn (Image $image): array => (new ImageMapper($image))->toPayload(), $parts);
    }

    /**
     * @param  Document[]  $parts
     * @return array<int, mixed>
     */
    protected function mapDocumentParts(array $parts): array
    {
        return array_map(static fn (Document $document): array => (new DocumentMapper($document))->toPayload(), $parts);
    }
}
