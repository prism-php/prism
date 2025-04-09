<?php

namespace Prism\Prism\Http\Controllers;

use Illuminate\Support\ItemNotFoundException;
use Prism\Prism\Exceptions\PrismServerException;
use Prism\Prism\Facades\PrismServer;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response as TextResponse;
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
                $data = [
                    'id' => $chunk->meta?->id ?? 'unknown',
                    'object' => 'chat.completion.chunk',
                    'created' => now()->timestamp,
                    'model' => $chunk->meta?->model ?? 'unknown',
                    'choices' => [[
                        'delta' => [
                            'role' => 'assistant',
                            'content' => $chunk->content ?? $chunk->text,
                        ],
                    ]],
                ];

                echo 'data: '.json_encode($data)."\n\n";
                ob_flush();
                flush();
            }

            echo "data: [DONE]\n";
            ob_flush();
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
        return $response->responseMessages
            ->whereInstanceOf(AssistantMessage::class)
            ->implode(fn (AssistantMessage $message): string => $message->content);
    }

    /**
     * @param  array<int, mixed>  $messages
     * @return array<int, UserMessage|AssistantMessage|SystemMessage>
     */
    protected function mapMessages(array $messages): array
    {
        return collect($messages)
            ->map(fn ($message): UserMessage|AssistantMessage|SystemMessage => match ($message['role']) {
                'user' => new UserMessage($message['content']),
                'assistant' => new AssistantMessage($message['content']),
                'system' => new SystemMessage($message['content']),
                default => throw new PrismServerException("Couldn't map messages to Prism messages")
            })
            ->toArray();
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
