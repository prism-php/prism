<?php

namespace Prism\Prism\Http\Controllers;

use Illuminate\Support\ItemNotFoundException;
use Prism\Prism\Exceptions\PrismServerException;
use Prism\Prism\Facades\PrismServer;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PrismChatController
{
    public function __invoke(): Response
    {
        request()->validate([
            'stream' => 'sometimes|boolean',
            'model' => 'string|required',
            'messages' => 'sometimes|array',
        ]);

        try {
            /** @var array<array{role: string, content: string}> $messages */
            $messages = request('messages');

            $prism = $this->resolvePrism(request('model'));

            $prism->withMessages($this->mapMessages($messages));

            if (request('stream')) {
                return $this->stream($prism);
            }

            return $this->chat($prism);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    protected function stream(PendingRequest $generator): Response
    {
        return response()->stream(function () use ($generator): void {
            $response = $generator->asStream();

            foreach ($response as $chunk) {
                if (! $chunk instanceof TextDeltaEvent) {
                    continue;
                }

                $data = [
                    'id' => $chunk->id,
                    'object' => 'chat.completion.chunk',
                    'created' => now()->timestamp,
                    'model' => 'unknown',
                    'choices' => [[
                        'delta' => [
                            'role' => 'assistant',
                            'content' => $chunk->delta,
                        ],
                    ]],
                ];

                echo 'data: '.json_encode($data)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            echo "data: [DONE]\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
        ]);
    }

    protected function error(Throwable $e): Response
    {
        return response()->json([
            'error' => [
                'message' => $e->getMessage(),
            ],
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    protected function chat(PendingRequest $generator): Response
    {
        $response = $generator->asText();

        $data = [
            'id' => $response->meta->id,
            'object' => 'chat.completion',
            'created' => now()->timestamp,
            'model' => $response->meta->model,
            'usage' => [
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
                'total_tokens' => $response->usage->promptTokens
                        + $response->usage->completionTokens,
            ],
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'content' => $this->textFromResponse($response),
                        'role' => 'assistant',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ];

        return response()->json($data);
    }

    protected function textFromResponse(TextResponse $response): string
    {
        return $response->text;
    }

    /**
     * @param  array<int, array{role: string, content: mixed}>  $messages
     * @return array<int, UserMessage|AssistantMessage|SystemMessage>
     */
    protected function mapMessages(array $messages): array
    {
        return collect($messages)
            ->map(fn (array $message): UserMessage|AssistantMessage|SystemMessage => match ($message['role']) {
                'user' => $this->mapUserMessage($message),
                'assistant' => new AssistantMessage($this->extractTextContent($message['content'])),
                'system' => new SystemMessage($this->extractTextContent($message['content'])),
                default => throw new PrismServerException("Couldn't map messages to Prism messages")
            })
            ->all();
    }

    /**
     * @param  array{role: string, content: mixed}  $message
     */
    protected function mapUserMessage(array $message): UserMessage
    {
        $content = $message['content'];

        // Si le contenu est une string simple, retourner un UserMessage classique
        if (is_string($content)) {
            return new UserMessage($content);
        }

        // Si le contenu est un array (format multimodal OpenAI)
        if (is_array($content)) {
            $textContent = '';
            $additionalContent = [];

            foreach ($content as $part) {
                if (! is_array($part)) {
                    continue;
                }
                if (! isset($part['type'])) {
                    continue;
                }
                if (! is_string($part['type'])) {
                    continue;
                }
                match ($part['type']) {
                    'text' => $textContent .= $part['text'] ?? '',
                    'image_url' => $additionalContent[] = $this->mapImageUrl($part),
                    default => null // Ignore unknown types
                };
            }

            return new UserMessage($textContent, $additionalContent);
        }

        // This line should never be reached due to the type guards above
        throw new PrismServerException('Invalid message content type');
    }

    /**
     * @param  array<string, mixed>  $imagePart
     */
    protected function mapImageUrl(array $imagePart): Image
    {
        $imageUrl = $imagePart['image_url'] ?? [];
        $url = $imageUrl['url'] ?? '';

        // Détecter si c'est une image base64 ou une URL
        if (str_starts_with((string) $url, 'data:')) {
            // Format: data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA...
            $parts = explode(',', (string) $url, 2);
            if (count($parts) === 2) {
                $metadata = $parts[0]; // data:image/png;base64
                $base64Data = $parts[1];

                // Extraire le mime type
                preg_match('/data:([^;]+)/', $metadata, $matches);
                $mimeType = $matches[1] ?? 'image/jpeg';

                return Image::fromBase64($base64Data, $mimeType);
            }
        }

        // C'est une URL
        return Image::fromUrl($url);
    }

    /**
     * @param  string|array<int, mixed>  $content
     */
    protected function extractTextContent(string|array $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        $text = '';
        foreach ($content as $part) {
            if (is_array($part) && isset($part['type']) && $part['type'] === 'text') {
                $text .= $part['text'] ?? '';
            }
        }

        return $text;
    }

    protected function resolvePrism(string $model): PendingRequest
    {
        try {
            $prism = PrismServer::prisms()
                ->sole('name', $model);
        } catch (ItemNotFoundException $e) {
            throw PrismServerException::unresolvableModel($model, $e);
        }

        return $prism['prism']();
    }
}
