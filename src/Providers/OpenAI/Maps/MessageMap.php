<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Exception;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
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
                'type' => 'function_call_output',
                'call_id' => $toolResult->toolCallId,
                'output' => $toolResult->result,
            ];
        }
    }

    protected function mapUserMessage(UserMessage $message): void
    {
        $imageParts = array_map(fn (Image $image): array => [
            'type' => 'image_url',
            'image_url' => [
                'url' => $image->isUrl()
                    ? $image->image
                    : sprintf('data:%s;base64,%s', $image->mimeType, $image->image),
            ],
        ], $message->images());

        $this->mappedMessages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => $message->text()],
                ...$imageParts,
            ],
        ];
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
                ...array_map(fn (ToolCall $toolCall): array => [
                    'id' => $toolCall->id,
                    'call_id' => $toolCall->callId,
                    'type' => 'function_call',
                    'name' => $toolCall->name,
                    'arguments' => json_encode($toolCall->arguments()),
                ], $message->toolCalls)
            );
        }
    }
}
