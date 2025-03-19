<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Exception;
use InvalidArgumentException;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\Support\Document;
use Prism\Prism\ValueObjects\Messages\Support\Image;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class MessageMap
{
    /** @var array<string, mixed> */
    protected array $contents = [];

    /**
     * @param  array<int, Message>  $messages
     * @param  SystemMessage[]  $systemPrompts
     */
    public function __construct(
        protected array $messages,
        protected array $systemPrompts = []
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $this->contents['contents'] = [];

        foreach ($this->messages as $message) {
            $this->mapMessage($message);
        }

        foreach ($this->systemPrompts as $systemPrompt) {
            $this->mapSystemMessage($systemPrompt);
        }

        return array_filter($this->contents);
    }

    protected function mapMessage(Message $message): void
    {
        match ($message::class) {
            UserMessage::class => $this->mapUserMessage($message),
            AssistantMessage::class => $this->mapAssistantMessage($message),
            ToolResultMessage::class => $this->mapToolResultMessage($message),
            default => throw new Exception('Could not map message type '.$message::class),
        };
    }

    protected function mapSystemMessage(SystemMessage $message): void
    {
        if (isset($this->contents['system_instruction'])) {
            throw new PrismException('Gemini only supports one system instruction.');
        }

        $this->contents['system_instruction'] = [
            'parts' => [
                [
                    'text' => $message->content,
                ],
            ],
        ];
    }

    protected function mapToolResultMessage(ToolResultMessage $message): void
    {
        $parts = [];
        foreach ($message->toolResults as $toolResult) {
            $parts[] = [
                'functionResponse' => [
                    'name' => $toolResult->toolName,
                    'response' => [
                        'name' => $toolResult->toolName,
                        'content' => json_encode($toolResult->result),
                    ],
                ],
            ];
        }

        $this->contents['contents'][] = [
            'role' => 'user',
            'parts' => $parts,
        ];
    }

    protected function mapUserMessage(UserMessage $message): void
    {
        $parts = [];

        if ($message->text() !== '' && $message->text() !== '0') {
            $parts[] = ['text' => $message->text()];
        }

        // Gemini docs suggest including text prompt after documents, but before images.
        $parts = array_merge($this->mapDocuments($message->documents()), $parts, $this->mapImages($message->images()));

        $this->contents['contents'][] = [
            'role' => 'user',
            'parts' => $parts,
        ];
    }

    protected function mapAssistantMessage(AssistantMessage $message): void
    {
        $parts = [];

        if ($message->content !== '' && $message->content !== '0') {
            $parts[] = ['text' => $message->content];
        }

        foreach ($message->toolCalls as $toolCall) {
            $parts[] = [
                'functionCall' => [
                    'name' => $toolCall->name,
                    ...count($toolCall->arguments()) ? [
                        'args' => $toolCall->arguments(),
                    ] : [],
                ],
            ];
        }

        $this->contents['contents'][] = [
            'role' => 'model',
            'parts' => $parts,
        ];
    }

    /**
     * @param  Image[]  $images
     * @return array<string,array<string,mixed>>
     */
    protected function mapImages(array $images): array
    {
        return array_map(fn (Image $image): array => [
            'inline_data' => [
                'mime_type' => $image->mimeType,
                'data' => $this->getImageData($image),
            ],
        ], $images);
    }

    protected function getImageData(Image $image): string
    {
        if ($image->isUrl()) {
            /** @var string $response */
            $response = file_get_contents($image->image);

            return base64_encode($response);
        }

        return $image->image;
    }

    /**
     * @param  Document[]  $documents
     * @return array<string,array<string,mixed>>
     */
    protected function mapDocuments(array $documents): array
    {
        return array_map(function (Document $document): array {

            if ($document->dataFormat === 'content') {
                throw new PrismException('Gemini does not support custom content documents.');
            }

            if ($document->isUrl()) {
                throw new InvalidArgumentException('URL document type is not supported by Anthropic');
            }

            return [
                'inline_data' => [
                    'mime_type' => $document->mimeType,
                    'data' => $document->dataFormat === 'base64' ? $document->document : base64_encode($document->document), // @phpstan-ignore argument.type
                ],
            ];
        }, $documents);
    }
}
