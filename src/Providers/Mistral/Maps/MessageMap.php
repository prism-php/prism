<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Maps;

use Exception;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\Support\Document;
use Prism\Prism\ValueObjects\Messages\Support\Image;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;

class MessageMap
{
    /** @var array<int, mixed> */
    protected array $mappedMessages = [];

    /**
     * @param  array<int, Message>  $messages
     * @param  SystemMessage[]  $systemPrompts
     */
    public function __construct(
        protected array $messages,
        protected array $systemPrompts
    ) {
        $this->messages = array_merge(
            $this->systemPrompts,
            $this->messages
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function __invoke(): array
    {
        array_map(
            fn (Message $message) => $this->mapMessage($message),
            $this->messages
        );

        return $this->mappedMessages;
    }

    protected function mapMessage(Message $message): void
    {
        match ($message::class) {
            UserMessage::class => $this->mapUserMessage($message),
            AssistantMessage::class => $this->mapAssistantMessage($message),
            ToolResultMessage::class => $this->mapToolResultMessage($message),
            SystemMessage::class => $this->mapSystemMessage($message),
            default => throw new Exception('Could not map message type '.$message::class),
        };
    }

    protected function mapSystemMessage(SystemMessage $message): void
    {
        $this->mappedMessages[] = [
            'role' => 'system',
            'content' => $message->content,
        ];
    }

    protected function mapToolResultMessage(ToolResultMessage $message): void
    {
        foreach ($message->toolResults as $toolResult) {
            $this->mappedMessages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolResult->toolCallId,
                'content' => $toolResult->result,
            ];
        }
    }

    protected function mapUserMessage(UserMessage $message): void
    {

        $this->mappedMessages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $message->text()],
                ...self::mapImageParts($message->images()),
                ...self::mapDocumentParts($message->documents()),
            ],
        ];
    }

    protected function mapAssistantMessage(AssistantMessage $message): void
    {
        $toolCalls = array_map(fn (ToolCall $toolCall): array => [
            'id' => $toolCall->id,
            'type' => 'function',
            'function' => [
                'name' => $toolCall->name,
                'arguments' => json_encode($toolCall->arguments()),
            ],
        ], $message->toolCalls);

        $this->mappedMessages[] = array_filter([
            'role' => 'assistant',
            'content' => $message->content,
            'tool_calls' => $toolCalls,
        ]);
    }

    /**
     * @param  Image[]  $images
     * @return array<int, mixed>
     */
    protected static function mapImageParts(array $images): array
    {
        return array_map(fn (Image $image): array => [
            'type' => 'image_url',
            'image_url' => [
                'url' => $image->isUrl()
                    ? $image->image
                    : sprintf('data:%s;base64,%s', $image->mimeType, $image->image),
            ],
        ], $images);
    }

    /**
     * @param  Document[]  $documents
     * @return array<int,mixed>
     */
    protected static function mapDocumentParts(array $documents): array
    {
        return array_map(function (Document $document): array {
            if (! $document->isUrl()) {
                throw new \InvalidArgumentException('Document types other than URL are not supported by Mistral');
            }

            return [
                'type' => 'document_url',
                'document_url' => $document->document,
                'document_name' => $document->documentTitle,
            ];
        }, $documents);
    }
}
