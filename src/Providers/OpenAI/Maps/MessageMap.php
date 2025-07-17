<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Exception;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\OpenAIFile;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
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
                'type' => 'function_call_output',
                'call_id' => $toolResult->toolCallResultId,
                'output' => $toolResult->result,
            ];
        }
    }

    protected function mapUserMessage(UserMessage $message): void
    {
        $this->mappedMessages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => $message->text()],
                ...self::mapImageParts($message->images()),
                ...self::mapDocumentParts($message->documents()),
                ...self::mapFileParts($message->files()),
            ],
            ...$message->additionalAttributes,
        ];
    }

    /**
     * @param  Image[]  $images
     * @return array<int, mixed>
     */
    protected static function mapImageParts(array $images): array
    {
        return array_map(fn (Image $image): array => (new ImageMapper($image))->toPayload(), $images);
    }

    /**
     * @param  Document[]  $documents
     * @return array<int,mixed>
     */
    protected static function mapDocumentParts(array $documents): array
    {
        return array_map(fn (Document $document): array => (new DocumentMapper($document))->toPayload(), $documents);
    }

    /**
     * @param  OpenAIFile[]  $files
     * @return array<int, mixed>
     */
    protected static function mapFileParts(array $files): array
    {
        return array_map(fn (OpenAIFile $file): array => [
            'type' => 'input_file',
            'file_id' => $file->fileId,
        ], $files);
    }

    protected function mapAssistantMessage(AssistantMessage $message): void
    {
        if ($message->content !== '' && $message->content !== '0') {
            $this->mappedMessages[] = [
                'role' => 'assistant',
                'content' => $message->content,
            ];
        }

        if ($message->toolCalls !== []) {
            array_push(
                $this->mappedMessages,
                ...array_filter(
                    array_map(fn (ToolCall $toolCall): ?array => is_null($toolCall->reasoningId) ? null : [
                        'type' => 'reasoning',
                        'id' => $toolCall->reasoningId,
                        'summary' => $toolCall->reasoningSummary,
                    ], $message->toolCalls)
                ),
                ...array_map(fn (ToolCall $toolCall): array => [
                    'id' => $toolCall->id,
                    'call_id' => $toolCall->resultId,
                    'type' => 'function_call',
                    'name' => $toolCall->name,
                    'arguments' => json_encode($toolCall->arguments()),
                ], $message->toolCalls)
            );
        }
    }
}
