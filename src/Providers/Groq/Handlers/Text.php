<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Groq\Handlers;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\Providers\Groq\Concerns\HandleResponseError;
use Prism\Prism\Providers\Groq\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\Groq\Maps\FinishReasonMap;
use Prism\Prism\Providers\Groq\Maps\MessageMap;
use Prism\Prism\Providers\Groq\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Groq\Maps\ToolMap;
use Prism\Prism\Providers\TextHandler;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;

class Text extends TextHandler
{
    use CallsTools, HandleResponseError, ProcessRateLimits;

    /**
     * @param  TextRequest  $request
     */
    #[\Override]
    public static function buildHttpRequestPayload(PrismRequest $request): array
    {
        if (! $request->is(TextRequest::class)) {
            throw new \InvalidArgumentException('Request must be an instance of '.TextRequest::class);
        }

        return Arr::whereNotNull([
            'model' => $request->model(),
            'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
            'max_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'tools' => ToolMap::map($request->tools()),
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
        ]);
    }

    protected function sendRequest(): void
    {
        $this->httpResponse = $this->client->post(
            'chat/completions',
            static::buildHttpRequestPayload($this->request),
        );
    }

    protected function handleToolCalls(): TextResponse
    {
        $data = $this->httpResponse->json();

        $toolResults = $this->callTools(
            $this->request->tools(),
            $this->mapToolCalls(data_get($data, 'choices.0.message.tool_calls', []) ?? []),
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
            text: data_get($data, 'choices.0.message.content') ?? '',
            finishReason: FinishReasonMap::map(data_get($data, 'choices.0.finish_reason', '')),
            toolCalls: $this->mapToolCalls(data_get($data, 'choices.0.message.tool_calls', []) ?? []),
            toolResults: [],
            usage: new Usage(
                data_get($data, 'usage.prompt_tokens'),
                data_get($data, 'usage.completion_tokens'),
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
                rateLimits: $this->processRateLimits($this->httpResponse),
            ),
            additionalContent: [],
        );
    }

    /**
     * @param  array<string, mixed>  $toolCalls
     * @return ToolCall[]
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'id'),
            name: data_get($toolCall, 'function.name'),
            arguments: data_get($toolCall, 'function.arguments'),
        ), $toolCalls);
    }
}
