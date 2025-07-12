<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Ollama\Concerns\HandleResponseError;
use Prism\Prism\Providers\Ollama\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Ollama\Maps\MessageMap;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use HandleResponseError;
    use MapsFinishReason;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): Response
    {
        $data = $this->sendRequest($request);

        $this->handleResponseError();

        $responseMessage = new AssistantMessage(
            data_get($data, 'message.content') ?? '',
        );

        $this->responseBuilder->addResponseMessage($responseMessage);

        $request->addMessage($responseMessage);

        $this->addStep($data, $request);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function addStep(array $data, Request $request): void
    {
        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'message.content') ?? '',
            finishReason: $this->mapFinishReason($data),
            usage: new Usage(
                data_get($data, 'prompt_eval_count', 0),
                data_get($data, 'eval_count', 0),
            ),
            meta: new Meta(
                id: '',
                model: $request->model(),
            ),
            messages: $request->messages(),
            additionalContent: [],
            systemPrompts: $request->systemPrompts(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendRequest(Request $request): array
    {
        if (count($request->systemPrompts()) > 1) {
            throw new PrismException('Ollama does not support multiple system prompts using withSystemPrompt / withSystemPrompts. However, you can provide additional system prompts by including SystemMessages in with withMessages.');
        }

        $this->httpResponse = $this->client->post('api/chat', [
            'model' => $request->model(),
            'system' => data_get($request->systemPrompts(), '0.content', ''),
            'messages' => (new MessageMap($request->messages()))->map(),
            'format' => $request->schema()->toArray(),
            'stream' => false,
            'options' => Arr::whereNotNull(array_merge([
                'temperature' => $request->temperature(),
                'num_predict' => $request->maxTokens() ?? 2048,
                'top_p' => $request->topP(),
            ], $request->providerOptions())),
        ]);

        return $this->httpResponse->json();
    }
}
