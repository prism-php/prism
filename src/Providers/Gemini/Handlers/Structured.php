<?php

namespace PrismPHP\Prism\Providers\Gemini\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use PrismPHP\Prism\Enums\Provider;
use PrismPHP\Prism\Exceptions\PrismException;
use PrismPHP\Prism\Providers\Gemini\Concerns\ValidatesResponse;
use PrismPHP\Prism\Providers\Gemini\Maps\FinishReasonMap;
use PrismPHP\Prism\Providers\Gemini\Maps\MessageMap;
use PrismPHP\Prism\Providers\Gemini\Maps\SchemaMap;
use PrismPHP\Prism\Structured\Request;
use PrismPHP\Prism\Structured\Response as StructuredResponse;
use PrismPHP\Prism\Structured\ResponseBuilder;
use PrismPHP\Prism\Structured\Step;
use PrismPHP\Prism\ValueObjects\Messages\AssistantMessage;
use PrismPHP\Prism\ValueObjects\Meta;
use PrismPHP\Prism\ValueObjects\Usage;
use Throwable;

class Structured
{
    use ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        try {
            $response = $this->sendRequest($request);
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }

        $this->validateResponse($response);

        $data = $response->json();

        $text = data_get($data, 'candidates.0.content.parts.0.text') ?? '';

        $responseMessage = new AssistantMessage($text);
        $this->responseBuilder->addResponseMessage($responseMessage);
        $request->addMessage($responseMessage);

        $this->responseBuilder->addStep(
            new Step(
                text: $text,
                finishReason: FinishReasonMap::map(
                    data_get($data, 'candidates.0.finishReason'),
                ),
                usage: new Usage(
                    data_get($data, 'usageMetadata.promptTokenCount', 0),
                    data_get($data, 'usageMetadata.candidatesTokenCount', 0)
                ),
                meta: new Meta(
                    id: data_get($data, 'id', ''),
                    model: data_get($data, 'modelVersion'),
                ),
                messages: $request->messages(),
                systemPrompts: $request->systemPrompts(),
            )
        );

        return $this->responseBuilder->toResponse();
    }

    public function sendRequest(Request $request): Response
    {
        $endpoint = "{$request->model()}:generateContent";

        $payload = (new MessageMap($request->messages(), $request->systemPrompts()))();

        $responseSchema = new SchemaMap($request->schema());

        $payload['generationConfig'] = array_merge([
            'response_mime_type' => 'application/json',
            'response_schema' => $responseSchema->toArray(),
        ], array_filter([
            'temperature' => $request->temperature(),
            'topP' => $request->topP(),
            'maxOutputTokens' => $request->maxTokens(),
        ]));

        $safetySettings = $request->providerMeta(Provider::Gemini, 'safetySettings');
        if (! empty($safetySettings)) {
            $payload['safetySettings'] = $safetySettings;
        }

        return $this->client->post($endpoint, $payload);
    }
}
