<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Z\Maps;

use Prism\Prism\Contracts\Message;
use Prism\Prism\Providers\Z\Enums\DocumentType;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

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
            $this->mapMessage(...),
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
            default => throw new \InvalidArgumentException('Unsupported message type: '.$message::class),
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
        $images = array_map(fn (Media $media): array => (new DocumentMapper($media, DocumentType::ImageUrl))->toPayload(), $message->images());
        $documents = array_map(fn (Document $document): array => (new DocumentMapper($document, DocumentType::FileUrl))->toPayload(), $message->documents());
        $videos = array_map(fn (Media $media): array => (new DocumentMapper($media, DocumentType::VideoUrl))->toPayload(), $message->videos());

        $this->mappedMessages[] = [
            'role' => 'user',
            'content' => [
                ...$images,
                ...$documents,
                ...$videos,
                ['type' => 'text', 'text' => $message->text()],
            ],
        ];
    }

    protected function mapAssistantMessage(AssistantMessage $message): void
    {

        $this->mappedMessages[] = array_filter([
            'role' => 'assistant',
            'content' => $message->content,
        ]);
    }
}
