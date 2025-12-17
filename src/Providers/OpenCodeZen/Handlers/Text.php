<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenCodeZen\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenCodeZen\Concerns\BuildsRequestOptions;
use Prism\Prism\Providers\OpenCodeZen\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenCodeZen\Concerns\ValidatesResponses;
use Prism\Prism\Providers\OpenCodeZen\Maps\MessageMap;
use Prism\Prism\Providers\OpenCodeZen\Maps\ToolCallMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Text
{
    use BuildsRequestOptions;
    use CallsTools;
    use MapsFinishReason;
    use ValidatesResponses;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): TextResponse
    {
        $data = $this->sendRequest($request);

        $this->validateResponse($data);

        $responseMessage = new AssistantMessage(
            Arr::get($data, 'choices.0.message.content') ?? '',
            ToolCallMap::map(Arr::get($data, 'choices.0.message.tool_calls', [])),
            []
        );

        $request = $request->addMessage($responseMessage);

        return match ($this->mapFinishReason($data)) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request),
            FinishReason::Stop, FinishReason::Length => $this->handleStop($data, $request),
            default => throw PrismException::providerResponseError('OpenCodeZen: unknown finish reason'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request): TextResponse
    {
        $toolResults = $this->callTools(
            $request->tools(),
            ToolCallMap::map(Arr::get($data, 'choices.0.message.tool_calls', []))
        );

        $request = $request->addMessage(new ToolResultMessage($toolResults));

        $this->addStep($data, $request, $toolResults);

        if ($this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleStop(array $data, Request $request): TextResponse
    {
        $this->addStep($data, $request);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendRequest(Request $request): array
    {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = $this->client->post(
            'chat/completions',
            array_merge([
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_tokens' => $request->maxTokens(),
            ], $this->buildRequestOptions($request))
        );

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, ToolResult>  $toolResults
     */
    protected function addStep(array $data, Request $request, array $toolResults = []): void
    {
        $this->responseBuilder->addStep(new Step(
            text: Arr::get($data, 'choices.0.message.content') ?? '',
            finishReason: $this->mapFinishReason($data),
            toolCalls: ToolCallMap::map(Arr::get($data, 'choices.0.message.tool_calls', [])),
            toolResults: $toolResults,
            providerToolCalls: [],
            usage: new Usage(
                Arr::get($data, 'usage.prompt_tokens'),
                Arr::get($data, 'usage.completion_tokens'),
            ),
            meta: new Meta(
                id: Arr::get($data, 'id'),
                model: Arr::get($data, 'model'),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [],
        ));
    }
}
