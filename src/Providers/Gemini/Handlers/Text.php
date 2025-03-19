<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Gemini\Concerns\ExtractSearchGroundings;
use Prism\Prism\Providers\Gemini\Concerns\ValidatesResponse;
use Prism\Prism\Providers\Gemini\Maps\FinishReasonMap;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
use Prism\Prism\Providers\Gemini\Maps\ToolCallMap;
use Prism\Prism\Providers\Gemini\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Gemini\Maps\ToolMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class Text
{
    use CallsTools, ExtractSearchGroundings, ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(
        protected PendingRequest $client,
        #[\SensitiveParameter] protected string $apiKey,
    ) {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): TextResponse
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        $isToolCall = ! empty(data_get($data, 'candidates.0.content.parts.0.functionCall'));

        $responseMessage = new AssistantMessage(
            data_get($data, 'message.content') ?? '',
            $isToolCall ? ToolCallMap::map(data_get($data, 'candidates.0.content.parts', [])) : [],
        );

        $this->responseBuilder->addResponseMessage($responseMessage);

        $request->addMessage($responseMessage);

        $finishReason = FinishReasonMap::map(
            data_get($data, 'candidates.0.finishReason'),
            $isToolCall
        );

        return match ($finishReason) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request),
            FinishReason::Stop, FinishReason::Length => $this->handleStop($data, $request, $finishReason),
            default => throw new PrismException('Gemini: unhandled finish reason'),
        };
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        try {
            $providerMeta = $request->providerMeta(Provider::Gemini);

            $generationConfig = array_filter([
                'temperature' => $request->temperature(),
                'topP' => $request->topP(),
                'maxOutputTokens' => $request->maxTokens(),
            ]);

            if ($request->tools() !== [] && ($providerMeta['searchGrounding'] ?? false)) {
                throw new Exception('Use of search grounding with custom tools is not currently supported by Prism.');
            }

            $tools = $providerMeta['searchGrounding'] ?? false
                ? [
                    [
                        'google_search' => (object) [],
                    ],
                ]
                : ($request->tools() !== [] ? ['function_declarations' => ToolMap::map($request->tools())] : []);

            $providerMeta = $request->providerMeta(Provider::Gemini);

            return $this->client->post(
                "{$request->model()}:generateContent",
                array_filter([
                    ...(new MessageMap($request->messages(), $request->systemPrompts()))(),
                    'cachedContent' => $providerMeta['cachedContentName'] ?? null,
                    'generationConfig' => $generationConfig !== [] ? $generationConfig : null,
                    'tools' => $tools !== [] ? $tools : null,
                    'tool_config' => $request->toolChoice() ? ToolChoiceMap::map($request->toolChoice()) : null,
                    'safetySettings' => $providerMeta['safetySettings'] ?? null,
                ])
            );
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleStop(array $data, Request $request, FinishReason $finishReason): TextResponse
    {
        $this->addStep($data, $request, $finishReason);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request): TextResponse
    {
        $toolResults = $this->callTools(
            $request->tools(),
            ToolCallMap::map(data_get($data, 'candidates.0.content.parts', []))
        );

        $request->addMessage(new ToolResultMessage($toolResults));

        $this->addStep($data, $request, FinishReason::ToolCalls, $toolResults);

        if ($this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(array $data, Request $request, FinishReason $finishReason, array $toolResults = []): void
    {
        $providerMeta = $request->providerMeta(Provider::Gemini);

        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'candidates.0.content.parts.0.text') ?? '',
            finishReason: $finishReason,
            toolCalls: $finishReason === FinishReason::ToolCalls ? ToolCallMap::map(data_get($data, 'candidates.0.content.parts', [])) : [],
            toolResults: $toolResults,
            usage: new Usage(
                promptTokens: isset($providerMeta['cachedContentName'])
                    ? (data_get($data, 'usageMetadata.promptTokenCount', 0) - data_get($data, 'usageMetadata.cachedContentTokenCount', 0))
                    : data_get($data, 'usageMetadata.promptTokenCount', 0),
                completionTokens: data_get($data, 'usageMetadata.candidatesTokenCount', 0),
                cacheReadInputTokens: data_get($data, 'usageMetadata.cachedContentTokenCount', null),
            ),
            meta: new Meta(
                id: data_get($data, 'id', ''),
                model: data_get($data, 'modelVersion'),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [
                ...$this->extractSearchGroundingContent($data),
            ],
        ));
    }
}
