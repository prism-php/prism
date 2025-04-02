<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Maps;

use BackedEnum;
use Exception;
use InvalidArgumentException;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\Support\Document;
use Prism\Prism\ValueObjects\Messages\Support\Image;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class MessageMap
{
    /**
     * @param  array<int, Message>  $messages
     * @param  array<string, mixed>  $requestProviderMeta
     * @return array<int, mixed>
     */
    public static function map(array $messages, array $requestProviderMeta = []): array
    {
        if (array_filter($messages, fn (Message $message): bool => $message instanceof SystemMessage) !== []) {
            throw new PrismException('Anthropic does not support SystemMessages in the messages array. Use withSystemPrompt or withSystemPrompts instead.');
        }

        return array_map(
            fn (Message $message): array => self::mapMessage($message, $requestProviderMeta),
            $messages
        );
    }

    /**
     * @param  SystemMessage[]  $messages
     * @return array<int, mixed>
     */
    public static function mapSystemMessages(array $messages): array
    {
        return array_map(
            fn (Message $message): array => self::mapSystemMessage($message),
            $messages
        );
    }

    /**
     * @param  array<string, mixed>  $requestProviderMeta
     * @return array<string, mixed>
     */
    protected static function mapMessage(Message $message, array $requestProviderMeta = []): array
    {
        return match ($message::class) {
            UserMessage::class => self::mapUserMessage($message, $requestProviderMeta),
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
        $cacheType = data_get($systemMessage->providerMeta(Provider::Anthropic), 'cacheType', null);

        return array_filter([
            'type' => 'text',
            'text' => $systemMessage->content,
            'cache_control' => $cacheType ? ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType] : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapToolResultMessage(ToolResultMessage $message): array
    {
        return [
            'role' => 'user',
            'content' => array_map(fn (ToolResult $toolResult): array => [
                'type' => 'tool_result',
                'tool_use_id' => $toolResult->toolCallId,
                'content' => $toolResult->result,
            ], $message->toolResults),
        ];
    }

    /**
     * @param  array<string, mixed>  $requestProviderMeta
     * @return array<string, mixed>
     */
    protected static function mapUserMessage(UserMessage $message, array $requestProviderMeta = []): array
    {
        $cacheType = data_get($message->providerMeta(Provider::Anthropic), 'cacheType', null);
        $cache_control = $cacheType ? ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType] : null;

        return [
            'role' => 'user',
            'content' => [
                array_filter([
                    'type' => 'text',
                    'text' => $message->text(),
                    'cache_control' => $cache_control,
                ]),
                ...self::mapImageParts($message->images(), $cache_control),
                ...self::mapDocumentParts($message->documents(), $cache_control, $requestProviderMeta),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAssistantMessage(AssistantMessage $message): array
    {
        $cacheType = data_get($message->providerMeta(Provider::Anthropic), 'cacheType', null);

        $content = [];

        if (isset($message->additionalContent['thinking']) && isset($message->additionalContent['thinking_signature'])) {
            $content[] = [
                'type' => 'thinking',
                'thinking' => $message->additionalContent['thinking'],
                'signature' => $message->additionalContent['thinking_signature'],
            ];
        }

        if (isset($message->additionalContent['messagePartsWithCitations'])) {
            foreach ($message->additionalContent['messagePartsWithCitations'] as $part) {
                $content[] = array_filter([
                    ...$part->toContentBlock(),
                    'cache_control' => $cacheType ? ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType] : null,
                ]);
            }
        } elseif ($message->content !== '' && $message->content !== '0') {

            $content[] = array_filter([
                'type' => 'text',
                'text' => $message->content,
                'cache_control' => $cacheType ? ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType] : null,
            ]);
        }

        $toolCalls = $message->toolCalls
            ? array_map(fn (ToolCall $toolCall): array => [
                'type' => 'tool_use',
                'id' => $toolCall->id,
                'name' => $toolCall->name,
                'input' => $toolCall->arguments(),
            ], $message->toolCalls)
            : [];

        return [
            'role' => 'assistant',
            'content' => array_merge($content, $toolCalls),
        ];
    }

    /**
     * @param  Image[]  $parts
     * @param  array<string, mixed>|null  $cache_control
     * @return array<int, mixed>
     */
    protected static function mapImageParts(array $parts, ?array $cache_control = null): array
    {
        return array_map(function (Image $image) use ($cache_control): array {
            if ($image->isUrl()) {
                throw new InvalidArgumentException('URL image type is not supported by Anthropic');
            }

            return array_filter([
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image->mimeType,
                    'data' => $image->image,
                ],
                'cache_control' => $cache_control,
            ]);
        }, $parts);
    }

    /**
     * @param  Document[]  $parts
     * @param  array<string, mixed>|null  $cache_control
     * @param  array<string, mixed>  $requestProviderMeta
     * @return array<int, mixed>
     */
    protected static function mapDocumentParts(array $parts, ?array $cache_control = null, array $requestProviderMeta = []): array
    {
        return array_map(function (Document $document) use ($cache_control, $requestProviderMeta): array {
            if ($document->isUrl()) {
                throw new InvalidArgumentException('URL document type is not supported by Anthropic');
            }

            return array_filter([
                'type' => 'document',
                'source' => array_filter([
                    'type' => $document->dataFormat,
                    'media_type' => $document->mimeType,
                    'data' => $document->dataFormat !== 'content' && ! is_array($document->document)
                        ? $document->document
                        : null,
                    'content' => $document->dataFormat === 'content' && is_array($document->document)
                        ? array_map(fn (string $chunk): array => ['type' => 'text', 'text' => $chunk], $document->document)
                        : null,
                ]),
                'title' => $document->documentTitle,
                'context' => $document->documentContext,
                'cache_control' => $cache_control,
                'citations' => data_get($requestProviderMeta, 'citations', data_get($document->providerMeta(Provider::Anthropic), 'citations', false))
                    ? ['enabled' => true]
                    : null,
            ]);
        }, $parts);
    }
}
