<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Maps;

use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class MessageMap
{
    /**
     * Map Prism messages to Replicate prompt format.
     *
     * @param  array<int, SystemMessage|UserMessage|AssistantMessage|ToolResultMessage>  $messages
     */
    public static function map(array $messages): string
    {
        $prompt = '';

        foreach ($messages as $message) {
            $prompt .= match ($message::class) {
                SystemMessage::class => self::mapSystemMessage($message),
                UserMessage::class => self::mapUserMessage($message),
                AssistantMessage::class => self::mapAssistantMessage($message),
                ToolResultMessage::class => '', // Replicate doesn't support tool results in this simple format
                default => '',
            };
        }

        return trim($prompt);
    }

    protected static function mapSystemMessage(SystemMessage $message): string
    {
        return "System: {$message->content}\n\n";
    }

    protected static function mapUserMessage(UserMessage $message): string
    {
        return "User: {$message->text()}\n\n";
    }

    protected static function mapAssistantMessage(AssistantMessage $message): string
    {
        return "Assistant: {$message->content}\n\n";
    }
}
