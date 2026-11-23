<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;

/**
 * The conversation as a provider can be sent it.
 *
 * A tool call whose arguments could not be parsed cannot go back as written,
 * and a provider requires the call beside its result. Once a later message
 * answers the call, the error is on record and the call is sent with no
 * arguments. A call nobody answered is left alone, so that mapping it still
 * fails with `malformed_tool_call_arguments`.
 */
class ReplayableMessages
{
    /**
     * @param  Message[]  $messages
     * @return Message[]
     */
    public static function of(array $messages): array
    {
        $answered = [];

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index] ?? null;

            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $toolResult) {
                    $answered[$toolResult->toolCallId] = true;
                }

                continue;
            }
            if (! $message instanceof AssistantMessage) {
                continue;
            }
            if ($answered === []) {
                continue;
            }

            $toolCalls = array_map(
                fn (ToolCall $toolCall): ToolCall => isset($answered[$toolCall->id])
                    ? $toolCall->withoutMalformedArguments()
                    : $toolCall,
                $message->toolCalls,
            );

            if ($toolCalls !== $message->toolCalls) {
                $messages[$index] = $message->withToolCalls($toolCalls);
            }
        }

        return $messages;
    }
}
