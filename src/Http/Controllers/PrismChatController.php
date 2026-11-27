<?php

namespace Prism\Prism\Http\Controllers;

use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\Str;
use JsonException;
use Prism\Prism\Exceptions\PrismServerException;
use Prism\Prism\Facades\PrismServer;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Prism Server's OpenAI-compatible endpoint.
 *
 * A registered prism is an intelligence layer an app offers other apps, not a pass-through to a provider
 * (G-315). So no response reveals what backs it: `model` is the registered name the caller asked for,
 * `id` and every tool call id are generated here, and no provider field is copied through.
 */
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

            $registered = $this->findPrism(request('model'));

            // The name every response reports as `model`: the registered one, never the provider's. Passed
            // down rather than kept on the controller, which the router reuses across requests.
            $name = $registered['name'];
            $prism = $registered['prism']();

            $prism->withMessages($this->mapMessages($messages));

            $returnedTools = $this->prepareReturnedTools($prism);

            if (request('stream')) {
                if ($returnedTools !== []) {
                    throw PrismServerException::streamingReturnedTools(request('model'));
                }

                return $this->stream($prism, $name);
            }

            return $this->chat($prism, $returnedTools, $name);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    protected function stream(PendingRequest $generator, string $model): Response
    {
        // One id, one timestamp and the registered name for every chunk of the response. The provider's
        // message and event ids never reach the caller.
        $id = $this->completionId();
        $created = now()->timestamp;

        return response()->stream(function () use ($generator, $id, $created, $model): void {
            try {
                foreach ($generator->asStream() as $chunk) {
                    if (! $chunk instanceof TextDeltaEvent) {
                        continue;
                    }

                    $this->sendEvent([
                        'id' => $id,
                        'object' => 'chat.completion.chunk',
                        'created' => $created,
                        'model' => $model,
                        'choices' => [[
                            'delta' => [
                                'role' => 'assistant',
                                'content' => $chunk->delta,
                            ],
                        ]],
                    ]);
                }
            } catch (Throwable $e) {
                // The status line is already sent, so a failure goes out as an OpenAI-style error event. It
                // must not escape: the exception page would be written into the stream, provider body and all.
                report($e);

                $this->sendEvent(['error' => [
                    'message' => 'The server could not complete the request.',
                    'type' => 'server_error',
                    'code' => null,
                ]]);
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

    /**
     * @param  array<string, mixed>  $data
     */
    protected function sendEvent(array $data): void
    {
        echo 'data: '.json_encode($data)."\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * An OpenAI-style completion id, generated here: the provider's response id is never sent.
     */
    protected function completionId(): string
    {
        return 'chatcmpl-'.Str::random(29);
    }

    /**
     * An OpenAI-shaped error that never carries an exception's own message (#150).
     *
     * Exception text can hold provider error bodies, upstream URLs, SQL or file paths from whatever a
     * registered closure threw. A caller-side failure gets its fixed public message and status; anything
     * else goes to the app's exception handler and the caller gets a generic 500.
     */
    protected function error(Throwable $e): Response
    {
        $renderer = PrismServer::errorRenderer();
        if ($renderer !== null) {
            try {
                $rendered = $renderer($e);
                if ($rendered instanceof Response) {
                    return $rendered;
                }
            } catch (Throwable $failure) {
                // A broken renderer must not turn into a leak: fall back to the safe default below.
                report($failure);
            }
        }

        if ($e instanceof PrismServerException && $e->status < 500) {
            return response()->json([
                'error' => [
                    'message' => $e->publicMessage,
                    'type' => $e->type,
                    'code' => $e->errorCode,
                ],
            ], $e->status);
        }

        report($e);

        return response()->json([
            'error' => [
                'message' => 'The server could not complete the request.',
                'type' => 'server_error',
                'code' => null,
            ],
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Find the registered prism's client-executed tools: the calls Prism Server returns to its caller.
     *
     * Each one is copied with withholdOtherCalls(), so a step that calls it runs nothing else. The
     * caller never sends tool results back, so a tool run beside it would act for nobody. The app's own
     * Tool instances are not modified. Only tools the app registered can be returned: the request's
     * `tools` and `tool_choice` are never read.
     *
     * @return array<int, string> the names of the returned tools
     */
    protected function prepareReturnedTools(PendingRequest $prism): array
    {
        $tools = $prism->tools();

        $returned = $this->returnedToolNames($tools);

        if ($returned === []) {
            return [];
        }

        $prism->withTools(array_map(
            fn (Tool $tool): Tool => $tool->isClientExecuted() ? (clone $tool)->withholdOtherCalls() : $tool,
            $tools,
        ));

        return $returned;
    }

    /**
     * A name counts only when exactly one registered tool has it, matching how a call is resolved to a
     * tool. A name shared with a tool the server runs is never returned to the caller.
     *
     * @param  array<int, Tool>  $tools
     * @return array<int, string>
     */
    protected function returnedToolNames(array $tools): array
    {
        $counts = [];
        $clientExecuted = [];

        foreach ($tools as $tool) {
            $counts[$tool->name()] = ($counts[$tool->name()] ?? 0) + 1;

            if ($tool->isClientExecuted()) {
                $clientExecuted[] = $tool->name();
            }
        }

        return array_values(array_unique(array_filter(
            $clientExecuted,
            fn (string $name): bool => $counts[$name] === 1,
        )));
    }

    /**
     * @param  array<int, string>  $returnedTools
     */
    protected function chat(PendingRequest $generator, array $returnedTools, string $model): Response
    {
        $response = $generator->asText();

        $toolCalls = $this->returnedToolCalls($response, $returnedTools);

        if ($toolCalls !== []) {
            $text = $this->textFromResponse($response);

            return response()->json([
                'id' => $this->completionId(),
                'object' => 'chat.completion',
                'created' => now()->timestamp,
                'model' => $model,
                'usage' => $this->mapUsage($response->usage),
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'content' => $text === '' ? null : $text,
                            'role' => 'assistant',
                            'tool_calls' => $this->mapToolCalls($toolCalls),
                        ],
                        'finish_reason' => 'tool_calls',
                    ],
                ],
            ]);
        }

        $data = [
            'id' => $this->completionId(),
            'object' => 'chat.completion',
            'created' => now()->timestamp,
            'model' => $model,
            'usage' => $this->mapUsage($response->usage),
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

    /**
     * The final step's calls to returned tools. Calls to tools the server runs are never listed.
     *
     * @param  array<int, string>  $returnedTools
     * @return array<int, ToolCall>
     */
    protected function returnedToolCalls(TextResponse $response, array $returnedTools): array
    {
        if ($returnedTools === []) {
            return [];
        }

        return array_values(array_filter(
            $response->toolCalls,
            fn (ToolCall $call): bool => in_array($call->name, $returnedTools, true),
        ));
    }

    /**
     * The calls in OpenAI's shape, each with a `call_` id generated here.
     *
     * A provider's call id names the provider (`toolu_` is Anthropic's), so none is sent. Calls that share
     * a provider id share the generated one, so the response stays consistent with itself.
     *
     * @param  array<int, ToolCall>  $calls
     * @return array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>
     */
    protected function mapToolCalls(array $calls): array
    {
        $ids = [];

        return array_map(function (ToolCall $call) use (&$ids): array {
            $providerId = $call->resultId !== null && $call->resultId !== '' ? $call->resultId : $call->id;

            if ($providerId === '') {
                return $this->mapToolCall($call, $this->toolCallId());
            }

            return $this->mapToolCall($call, $ids[$providerId] ??= $this->toolCallId());
        }, $calls);
    }

    protected function toolCallId(): string
    {
        return 'call_'.Str::random(24);
    }

    /**
     * OpenAI's shape: `arguments` is a JSON string, `{}` when there are none.
     *
     * @return array{id: string, type: string, function: array{name: string, arguments: string}}
     */
    protected function mapToolCall(ToolCall $call, string $id): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => [
                'name' => $call->name,
                'arguments' => $this->argumentsJson($call),
            ],
        ];
    }

    /**
     * The model's own argument string when it is a JSON object, so the caller reads what the model sent.
     * Otherwise the arguments re-encoded as an object: `{}`, never `[]`, for none.
     */
    protected function argumentsJson(ToolCall $call): string
    {
        if (is_string($call->arguments)) {
            try {
                if (json_decode($call->arguments, false, 512, JSON_THROW_ON_ERROR) instanceof stdClass) {
                    return $call->arguments;
                }
            } catch (JsonException) {
                // Not JSON: fall through to the re-encoded object.
            }

            // Still the model's own text: this API's callers validate it, and
            // an empty object would present a failed call as a real one.
            if ($call->hasMalformedArguments()) {
                return $call->arguments;
            }
        } elseif ($call->hasMalformedArguments()) {
            return json_encode($call->arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return json_encode($call->argumentsAsObject(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Prism reports cached input apart from `promptTokens`; the OpenAI shape
     * counts it inside `prompt_tokens`.
     *
     * @return array<string, mixed>
     */
    protected function mapUsage(Usage $usage): array
    {
        $promptTokens = $usage->promptTokens
            + ($usage->cacheReadInputTokens ?? 0)
            + ($usage->cacheWriteInputTokens ?? 0);

        return array_filter([
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'total_tokens' => $promptTokens + $usage->completionTokens,
            'prompt_tokens_details' => $usage->cacheReadInputTokens === null
                ? null
                : ['cached_tokens' => $usage->cacheReadInputTokens],
            'completion_tokens_details' => $usage->thoughtTokens === null
                ? null
                : ['reasoning_tokens' => $usage->thoughtTokens],
        ], fn (mixed $value): bool => $value !== null);
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
        // array_values: the index reported to the caller is the position in its own `messages` array.
        return collect(array_values($messages))
            ->map(fn (array $message, int $index): UserMessage|AssistantMessage|SystemMessage => match ($message['role']) {
                'user' => $this->mapUserMessage($message),
                'assistant' => $this->mapAssistantMessage($message, $index),
                'system' => new SystemMessage($this->extractTextContent($message['content'])),
                default => throw PrismServerException::invalidMessages("Couldn't map messages to Prism messages")
            })
            ->all();
    }

    /**
     * Tool calls in the history are refused, as `tool` messages are: Prism Server takes no tool results
     * back, and a caller must not be able to claim a call the server never returned.
     *
     * @param  array{role: string, content: mixed, tool_calls?: mixed, function_call?: mixed}  $message
     * @param  int  $index  the message's position in the caller's `messages` array
     */
    protected function mapAssistantMessage(array $message, int $index = 0): AssistantMessage
    {
        if (! empty($message['tool_calls']) || ! empty($message['function_call'])) {
            throw PrismServerException::replayedToolCalls($index);
        }

        /** @var string|array<int, mixed> $content */
        $content = $message['content'];

        return new AssistantMessage($this->extractTextContent($content));
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
        throw PrismServerException::invalidMessages('Invalid message content type');
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
        return $this->findPrism($model)['prism']();
    }

    /**
     * The registration the request names. Its `name` is what a response reports, not the request's string:
     * `sole` compares loosely.
     *
     * @return array{name: string, prism: callable():PendingRequest}
     */
    protected function findPrism(string $model): array
    {
        try {
            return PrismServer::prisms()
                ->sole('name', $model);
        } catch (ItemNotFoundException $e) {
            throw PrismServerException::unresolvableModel($model, $e);
        }
    }
}
