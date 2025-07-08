<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Gemini\Concerns\ExtractSearchGroundings;
use Prism\Prism\Providers\Gemini\Concerns\HandleResponseError;
use Prism\Prism\Providers\Gemini\Maps\FinishReasonMap;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
use Prism\Prism\Providers\Gemini\Maps\ToolCallMap;
use Prism\Prism\Providers\Gemini\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Gemini\Maps\ToolMap;
use Prism\Prism\Providers\TextHandler;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\ValueObjects\Usage;

class Text extends TextHandler
{
    use CallsTools, ExtractSearchGroundings, HandleResponseError;

    /**
     * @param  TextRequest  $request
     */
    #[\Override]
    public static function buildHttpRequestPayload(PrismRequest $request): array
    {
        if (! $request->is(TextRequest::class)) {
            throw new \InvalidArgumentException('Request must be an instance of '.TextRequest::class);
        }

        $providerOptions = $request->providerOptions();

        $thinkingConfig = Arr::whereNotNull([
            'thinkingBudget' => $providerOptions['thinkingBudget'] ?? null,
        ]);

        $generationConfig = Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'topP' => $request->topP(),
            'maxOutputTokens' => $request->maxTokens(),
            'thinkingConfig' => $thinkingConfig !== [] ? $thinkingConfig : null,
        ]);

        if ($request->tools() !== [] && $request->providerTools() != []) {
            throw new PrismException('Use of provider tools with custom tools is not currently supported by Gemini.');
        }

        $tools = [];

        if ($request->providerTools() !== []) {
            $tools = [
                Arr::mapWithKeys(
                    $request->providerTools(),
                    fn (ProviderTool $providerTool): array => [$providerTool->type => (object) []]
                ),
            ];
        }

        if ($request->tools() !== []) {
            $tools['function_declarations'] = ToolMap::map($request->tools());
        }

        return Arr::whereNotNull([
            ...(new MessageMap($request->messages(), $request->systemPrompts()))(),
            'cachedContent' => $providerOptions['cachedContentName'] ?? null,
            'generationConfig' => $generationConfig !== [] ? $generationConfig : null,
            'tools' => $tools !== [] ? $tools : null,
            'tool_config' => $request->toolChoice() ? ToolChoiceMap::map($request->toolChoice()) : null,
            'safetySettings' => $providerOptions['safetySettings'] ?? null,
        ]);
    }

    protected function sendRequest(): void
    {
        $this->httpResponse = $this->client->post(
            "{$this->request->model()}:generateContent",
            static::buildHttpRequestPayload($this->request),
        );
    }

    protected function handleToolCalls(): TextResponse
    {
        $data = $this->httpResponse->json();

        $toolResults = $this->callTools(
            $this->request->tools(),
            ToolCallMap::map(data_get($data, 'candidates.0.content.parts', []))
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
        $providerOptions = $this->request->providerOptions();

        $isToolCall = ! empty(data_get($data, 'candidates.0.content.parts.0.functionCall'));

        $this->tempResponse = new Response(
            steps: new Collection,
            responseMessages: new Collection,
            messages: new Collection,
            text: data_get($data, 'candidates.0.content.parts.0.text') ?? '',
            finishReason: FinishReasonMap::map(
                data_get($data, 'candidates.0.finishReason'),
                $isToolCall
            ),
            toolCalls: $isToolCall ? ToolCallMap::map(data_get($data, 'candidates.0.content.parts', [])) : [],
            toolResults: [],
            usage: new Usage(
                promptTokens: isset($providerOptions['cachedContentName'])
                    ? (data_get($data, 'usageMetadata.promptTokenCount', 0) - data_get($data, 'usageMetadata.cachedContentTokenCount', 0))
                    : data_get($data, 'usageMetadata.promptTokenCount', 0),
                completionTokens: data_get($data, 'usageMetadata.candidatesTokenCount', 0),
                cacheReadInputTokens: data_get($data, 'usageMetadata.cachedContentTokenCount', null),
                thoughtTokens: data_get($data, 'usageMetadata.thoughtsTokenCount', null),
            ),
            meta: new Meta(
                id: data_get($data, 'id', ''),
                model: data_get($data, 'modelVersion'),
            ),
            additionalContent: [
                ...$this->extractSearchGroundingContent($data),
            ],
        );
    }
}
