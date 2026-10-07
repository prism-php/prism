<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Maps;

use Exception;
use Illuminate\Support\Arr;
use Prism\Prism\Contracts\DeclaresCacheStability;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\CacheStability;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Anthropic\Concerns\NormalizesCacheControl;
use Prism\Prism\Providers\Support\Payload;
use Prism\Prism\Support\Json;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class MessageMap
{
    use NormalizesCacheControl;

    /**
     * @param  array<int, Message>  $messages
     * @param  array<string, mixed>  $requestProviderOptions
     * @return array<int, mixed>
     */
    public static function map(array $messages, array $requestProviderOptions = []): array
    {
        if (array_filter($messages, fn (Message $message): bool => $message instanceof SystemMessage) !== []) {
            throw new PrismException('Anthropic does not support SystemMessages in the messages array. Use withSystemPrompt or withSystemPrompts instead.');
        }

        $mappedMessages = array_map(
            fn (Message $message): array => self::mapMessage($message, $requestProviderOptions),
            $messages
        );

        $mappedMessages = self::applyCacheHints($messages, $mappedMessages);

        if (isset($requestProviderOptions['tool_result_cache_type'])) {
            $lastToolResultIndex = null;

            for ($i = count($mappedMessages) - 1; $i >= 0; $i--) {
                if ($mappedMessages[$i]['role'] === 'user' &&
                    isset($mappedMessages[$i]['content'][0]['type']) &&
                    $mappedMessages[$i]['content'][0]['type'] === 'tool_result') {
                    $lastToolResultIndex = $i;
                    break;
                }
            }

            if ($lastToolResultIndex !== null) {
                $lastContent = &$mappedMessages[$lastToolResultIndex]['content'];
                $lastContent[count($lastContent) - 1]['cache_control'] = [
                    'type' => $requestProviderOptions['tool_result_cache_type'],
                ];
            }
        }

        return $mappedMessages;
    }

    /**
     * @param  SystemMessage[]  $messages
     * @return array<int, mixed>
     */
    public static function mapSystemMessages(array $messages): array
    {
        return array_map(
            self::mapSystemMessage(...),
            $messages
        );
    }

    /**
     * @param  array<string, mixed>  $requestProviderOptions
     * @return array<string, mixed>
     */
    protected static function mapMessage(Message $message, array $requestProviderOptions = []): array
    {
        return match ($message::class) {
            UserMessage::class => self::mapUserMessage($message, $requestProviderOptions),
            AssistantMessage::class => self::mapAssistantMessage($message),
            ToolResultMessage::class => self::mapToolResultMessage($message),
            default => throw new Exception('Could not map message type '.$message::class),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapSystemMessage(SystemMessage $systemMessage): array
    {
        return Payload::compact([
            'type' => 'text',
            'text' => $systemMessage->content,
            'cache_control' => self::normalizeCacheControl($systemMessage),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapToolResultMessage(ToolResultMessage $message): array
    {
        $toolResults = $message->toolResults;
        $totalResults = count($toolResults);

        return [
            'role' => 'user',
            'content' => array_map(function (ToolResult $toolResult, int $index) use ($message, $totalResults): array {
                // Only add cache_control to the last tool result
                $isLastResult = $index === $totalResults - 1;

                return Payload::compact([
                    'type' => 'tool_result',
                    'tool_use_id' => $toolResult->toolCallId,
                    'content' => $toolResult->result,
                    'cache_control' => $isLastResult ? self::normalizeCacheControl($message) : null,
                ]);
            }, $toolResults, array_keys($toolResults)),
        ];
    }

    /**
     * @param  array<string, mixed>  $requestProviderOptions
     * @return array<string, mixed>
     */
    protected static function mapUserMessage(UserMessage $message, array $requestProviderOptions = []): array
    {
        $cacheControl = self::normalizeCacheControl($message);

        return [
            'role' => 'user',
            'content' => self::cacheFinalContentBlock([
                Payload::compact([
                    'type' => 'text',
                    'text' => $message->text(),
                ]),
                ...self::mapImageParts($message->images()),
                ...self::mapDocumentParts($message->documents(), requestProviderOptions: $requestProviderOptions),
            ], $cacheControl),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAssistantMessage(AssistantMessage $message): array
    {
        $cacheControl = self::normalizeCacheControl($message);

        $content = [];

        if (isset($message->additionalContent['thinking']) && isset($message->additionalContent['thinking_signature'])) {
            $content[] = [
                'type' => 'thinking',
                'thinking' => $message->additionalContent['thinking'],
                'signature' => $message->additionalContent['thinking_signature'],
            ];
        }

        if (isset($message->additionalContent['citations'])) {
            foreach ($message->additionalContent['citations'] as $part) {
                $content[] = Payload::compact(CitationsMapper::mapToAnthropic($part));
            }
        } elseif ($message->content !== '') {

            // Payload::compact, not array_filter: a bare array_filter would
            // also drop 'text' => '0', which is falsy but is real output.
            $content[] = Payload::compact([
                'type' => 'text',
                'text' => $message->content,
            ]);
        }

        $toolCalls = $message->toolCalls
            ? array_map(fn (ToolCall $toolCall): array => [
                'type' => 'tool_use',
                'id' => $toolCall->id,
                'name' => $toolCall->name,
                'input' => $toolCall->argumentsAsObject(),
            ], $message->toolCalls)
            : [];

        if (isset($message->additionalContent['provider_tool_calls'])) {
            foreach ($message->additionalContent['provider_tool_calls'] as $toolCall) {
                $content[] = Payload::compact([
                    'type' => $toolCall['type'] ?? 'server_tool_use',
                    'id' => $toolCall['id'] ?? null,
                    'name' => $toolCall['name'] ?? null,
                    'input' => isset($toolCall['input']) && $toolCall['input'] !== '' ? Json::decode((string) $toolCall['input'], preservingContainerTypes: true) : new \stdClass,
                ]);
            }
        }

        if (isset($message->additionalContent['provider_tool_results'])) {
            foreach ($message->additionalContent['provider_tool_results'] as $toolResult) {
                $content[] = Payload::compact([
                    'type' => $toolResult['type'],
                    'tool_use_id' => $toolResult['tool_use_id'] ?? null,
                    'content' => $toolResult['content'] ?? null,
                ]);
            }
        }

        return [
            'role' => 'assistant',
            'content' => self::cacheFinalContentBlock(array_merge($content, $toolCalls), $cacheControl),
        ];
    }

    /**
     * Turn a portable stability declaration into Anthropic's breakpoint.
     *
     * THE RULE IS A DEFINITION, NOT A HEURISTIC. A breakpoint says "everything
     * before this is the same next turn", so it belongs after the last STABLE
     * message that precedes the first VOLATILE one. Deriving it means a caller
     * says what they know -- which parts change -- without counting markers or
     * learning that Anthropic allows four.
     *
     * Exactly one breakpoint is derived, and only when the caller placed none
     * themselves. Someone who has written `cacheType` is working in Anthropic's
     * idiom already; adding to their marks would spend one of the four they are
     * budgeting. A volatile-first conversation gets nothing, because nothing
     * stable precedes it -- there is no prefix to cache.
     *
     * @param  array<int, Message>  $messages
     * @param  array<int, array<string, mixed>>  $mapped
     * @return array<int, array<string, mixed>>
     */
    protected static function applyCacheHints(array $messages, array $mapped): array
    {
        // A caller who has already placed a breakpoint is working in
        // Anthropic's idiom and budgeting against its limit of four. Read from
        // the MAPPED payload rather than from the messages, so any route to a
        // mark -- a message's `cacheType`, a tool result's, a request option --
        // counts as the caller having taken charge.
        if (str_contains(json_encode($mapped) ?: '', 'cache_control')) {
            return $mapped;
        }

        $messages = array_values($messages);
        $breakpoint = null;

        foreach ($messages as $index => $message) {
            if (! $message instanceof DeclaresCacheStability) {
                continue;
            }

            $stability = $message->cacheStability();

            if ($stability === CacheStability::Volatile) {
                break;
            }

            if ($stability === CacheStability::Stable) {
                $breakpoint = $index;
            }
        }

        if ($breakpoint === null || ! isset($mapped[$breakpoint]['content'])) {
            return $mapped;
        }

        $hinted = $messages[$breakpoint];
        $ttl = $hinted instanceof DeclaresCacheStability ? $hinted->cacheTtl() : null;

        $mapped[$breakpoint]['content'] = self::cacheFinalContentBlock(
            $mapped[$breakpoint]['content'],
            Arr::whereNotNull(['type' => 'ephemeral', 'ttl' => $ttl]),
        );

        return $mapped;
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     * @param  array<string, mixed>|null  $cacheControl
     * @return array<int, array<string, mixed>>
     */
    protected static function cacheFinalContentBlock(array $content, ?array $cacheControl): array
    {
        $last = array_key_last($content);

        // Thinking blocks cannot carry an explicit cache breakpoint.
        if ($cacheControl !== null && $last !== null && ! in_array($content[$last]['type'] ?? null, ['thinking', 'redacted_thinking'], true)) {
            $content[$last]['cache_control'] = $cacheControl;
        }

        return $content;
    }

    /**
     * @param  Image[]  $parts
     * @param  array<string, mixed>|null  $cacheControl
     * @return array<int, mixed>
     */
    protected static function mapImageParts(array $parts, ?array $cacheControl = null): array
    {
        return array_map(
            fn (Image $image): array => (new ImageMapper($image, $cacheControl))->toPayload(),
            $parts
        );
    }

    /**
     * @param  Document[]  $parts
     * @param  array<string, mixed>|null  $cacheControl
     * @param  array<string, mixed>  $requestProviderOptions
     * @return array<int, mixed>
     */
    protected static function mapDocumentParts(array $parts, ?array $cacheControl = null, array $requestProviderOptions = []): array
    {
        return array_map(
            fn (Document $document): array => (new DocumentMapper($document, $cacheControl, $requestProviderOptions))->toPayload(),
            $parts
        );
    }
}
