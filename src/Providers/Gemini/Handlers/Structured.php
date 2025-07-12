<?php

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Providers\Gemini\Concerns\HandleResponseError;
use Prism\Prism\Providers\Gemini\Maps\FinishReasonMap;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
use Prism\Prism\Providers\Gemini\Maps\SchemaMap;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use HandleResponseError;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $data = $this->sendRequest($request);

        $this->handleResponseError();

        $responseMessage = new AssistantMessage(data_get($data, 'candidates.0.content.parts.0.text') ?? '');

        $this->responseBuilder->addResponseMessage($responseMessage);

        $request->addMessage($responseMessage);

        $this->addStep($data, $request);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @return array<string, mixed>
     */
    public function sendRequest(Request $request): array
    {
        $providerOptions = $request->providerOptions();

        $this->httpResponse = $this->client->post(
            "{$request->model()}:generateContent",
            Arr::whereNotNull([
                ...(new MessageMap($request->messages(), $request->systemPrompts()))(),
                'cachedContent' => $providerOptions['cachedContentName'] ?? null,
                'generationConfig' => Arr::whereNotNull([
                    'response_mime_type' => 'application/json',
                    'response_schema' => (new SchemaMap($request->schema()))->toArray(),
                    'temperature' => $request->temperature(),
                    'topP' => $request->topP(),
                    'maxOutputTokens' => $request->maxTokens(),
                    'thinkingConfig' => Arr::whereNotNull([
                        'thinkingBudget' => $providerOptions['thinkingBudget'] ?? null,
                    ]) ?: null,
                ]),
                'safetySettings' => $providerOptions['safetySettings'] ?? null,
            ])
        );

        return $this->httpResponse->json();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function addStep(array $data, Request $request): void
    {
        $this->responseBuilder->addStep(
            new Step(
                text: data_get($data, 'candidates.0.content.parts.0.text') ?? '',
                finishReason: FinishReasonMap::map(
                    data_get($data, 'candidates.0.finishReason'),
                ),
                usage: new Usage(
                    promptTokens: data_get($data, 'usageMetadata.promptTokenCount', 0),
                    completionTokens: data_get($data, 'usageMetadata.candidatesTokenCount', 0),
                    thoughtTokens: data_get($data, 'usageMetadata.thoughtsTokenCount', null),
                    cacheReadInputTokens: data_get($data, 'usageMetadata.cachedContentTokenCount', null),
                ),
                meta: new Meta(
                    id: data_get($data, 'id', ''),
                    model: data_get($data, 'modelVersion'),
                ),
                messages: $request->messages(),
                systemPrompts: $request->systemPrompts(),
            )
        );
    }
}
