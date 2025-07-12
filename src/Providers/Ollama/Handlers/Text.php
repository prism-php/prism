<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\Handlers;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Ollama\Concerns\HandleResponseError;
use Prism\Prism\Providers\Ollama\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Ollama\Maps\MessageMap;
use Prism\Prism\Providers\Ollama\Maps\ToolMap;
use Prism\Prism\Providers\TextHandler;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;

class Text extends TextHandler
{
    use CallsTools;
    use HandleResponseError;
    use MapsFinishReason;

    /**
     * @param  TextRequest  $request
     */
    #[\Override]
    public static function buildHttpRequestPayload(PrismRequest $request): array
    {
        if (! $request->is(TextRequest::class)) {
            throw new \InvalidArgumentException('Request must be an instance of '.TextRequest::class);
        }

        if (count($request->systemPrompts()) > 1) {
            throw new PrismException('Ollama does not support multiple system prompts using withSystemPrompt / withSystemPrompts. However, you can provide additional system prompts by including SystemMessages in with withMessages.');
        }

        return [
            'model' => $request->model(),
            'system' => data_get($request->systemPrompts(), '0.content', ''),
            'messages' => (new MessageMap($request->messages()))->map(),
            'tools' => ToolMap::map($request->tools()),
            'stream' => false,
            'options' => Arr::whereNotNull(array_merge([
                'temperature' => $request->temperature(),
                'num_predict' => $request->maxTokens() ?? 2048,
                'top_p' => $request->topP(),
            ], $request->providerOptions())),
        ];
    }

    protected function sendRequest(): void
    {
        $this->httpResponse = $this->client->post(
            'api/chat',
            static::buildHttpRequestPayload($this->request)
        );
    }

    protected function handleToolCalls(): TextResponse
    {
        $data = $this->httpResponse->json();

        $toolResults = $this->callTools(
            $this->request->tools(),
            $this->mapToolCalls(data_get($data, 'message.tool_calls', [])),
        );

        $this->request->addMessage(new ToolResultMessage($toolResults));

        $this->addStep($toolResults);

        if ($this->shouldContinue()) {
            return $this->handle();
        }

        return $this->responseBuilder->toResponse();
    }

    protected function prepareTempResponse(): void
    {
        $data = $this->httpResponse->json();

        $this->tempResponse = new TextResponse(
            steps: new Collection,
            responseMessages: new Collection,
            messages: new Collection,
            text: data_get($data, 'message.content') ?? '',
            finishReason: $this->mapFinishReason($data),
            toolCalls: $this->mapToolCalls(data_get($data, 'message.tool_calls', []) ?? []),
            toolResults: [],
            usage: new Usage(
                data_get($data, 'prompt_eval_count', 0),
                data_get($data, 'eval_count', 0),
            ),
            meta: new Meta(
                id: '',
                model: $this->request->model(),
            ),
            additionalContent: [],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'id') ?? '',
            name: data_get($toolCall, 'function.name'),
            arguments: data_get($toolCall, 'function.arguments'),
        ), $toolCalls);
    }
}
