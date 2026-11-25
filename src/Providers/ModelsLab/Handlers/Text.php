<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ModelsLab\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\ModelsLab\Concerns\MapsFinishReason;
use Prism\Prism\Providers\ModelsLab\Concerns\ValidatesResponse;
use Prism\Prism\Providers\ModelsLab\Maps\MessageMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Text
{
    use MapsFinishReason;
    use ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(
        protected PendingRequest $client,
        #[\SensitiveParameter] protected string $apiKey
    ) {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): TextResponse
    {
        $data = $this->sendRequest($request);

        $this->validateResponse($data);

        return match ($this->mapFinishReason($data)) {
            FinishReason::Stop => $this->handleStop($data, $request),
            FinishReason::Length => throw new PrismException('ModelsLab: max tokens exceeded'),
            default => throw new PrismException('ModelsLab: unknown finish reason'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleStop(array $data, Request $request): TextResponse
    {
        $this->addStep($data, $request);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendRequest(Request $request): array
    {
        /** @var Response $response */
        $response = $this->client->post(
            'v7/llm/chat/completions',
            array_merge([
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
            ], Arr::whereNotNull([
                'max_tokens' => $request->maxTokens(),
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'presence_penalty' => $request->providerOptions('presence_penalty'),
                'frequency_penalty' => $request->providerOptions('frequency_penalty'),
            ]))
        );

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function addStep(array $data, Request $request): void
    {
        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'choices.0.message.content') ?? '',
            finishReason: $this->mapFinishReason($data),
            toolCalls: [],
            toolResults: [],
            providerToolCalls: [],
            usage: new Usage(
                data_get($data, 'usage.prompt_tokens', 0),
                data_get($data, 'usage.completion_tokens', 0),
            ),
            meta: new Meta(
                id: data_get($data, 'id', ''),
                model: data_get($data, 'model', ''),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [],
            raw: $data,
        ));
    }
}
