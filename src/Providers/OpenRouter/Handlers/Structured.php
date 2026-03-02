<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Prism\Prism\Providers\DeepSeek\Maps\ToolCallMap;
use Prism\Prism\Providers\OpenRouter\Concerns\BuildsRequestOptions;
use Prism\Prism\Providers\OpenRouter\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenRouter\Concerns\ValidatesResponses;
use Prism\Prism\Providers\OpenRouter\Maps\MessageMap;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Structured
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

    public function handle(Request $request): StructuredResponse
    {
        $data = $this->sendRequest($request);

        $this->validateResponse($data);

        return match ($this->mapFinishReason($data)) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request),
            FinishReason::Stop, FinishReason::Length => $this->handleStop($data, $request),
            default => throw new PrismException('OpenRouter: unknown finish reason'),
        };
    }

    /**
     * @see https://openrouter.ai/docs/features/structured-outputs
     *
     * @return array<string, mixed>
     */
    protected function sendRequest(Request $request): array
    {
        /** @var Response $response */
        $response = $this->client->post(
            'chat/completions',
            array_merge([
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_tokens' => $request->maxTokens(),
                'structured_outputs' => true,
            ], $this->buildRequestOptions($request, [
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $request->schema()->name(),
                        'strict' => true,
                        'schema' => $request->schema()->toArray(),
                    ],
                ],
            ]))
        );

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateResponse(array $data): void
    {
        if ($data === []) {
            throw PrismException::providerResponseError('OpenRouter Error: Empty response');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request): StructuredResponse
    {
        $toolCalls = ToolCallMap::map(data_get($data, 'choices.0.message.tool_calls', []));

        $hasPendingToolCalls = false;
        $approvalRequests = [];
        $toolResults = $this->callTools($request->tools(), $toolCalls, $hasPendingToolCalls, $approvalRequests);

        $toolApprovalRequests = array_map(
            fn (ToolCall $tc): ToolApprovalRequest => new ToolApprovalRequest(approvalId: $tc->id, toolCallId: $tc->id),
            $approvalRequests,
        );

        $this->addStep($data, $request, $toolResults, $toolApprovalRequests);

        $request = $request->addMessage(new AssistantMessage(
            data_get($data, 'choices.0.message.content') ?? '',
            $toolCalls,
            [],
            $toolApprovalRequests,
        ));
        $request = $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        if (! $hasPendingToolCalls && $this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleStop(array $data, Request $request): StructuredResponse
    {
        $this->addStep($data, $request);

        try {
            return $this->responseBuilder->toResponse();
        } catch (PrismStructuredDecodingException $e) {
            $context = sprintf(
                "\nModel: %s\nFinish reason: %s\nRaw choices: %s",
                data_get($data, 'model', 'unknown'),
                data_get($data, 'choices.0.finish_reason', 'unknown'),
                json_encode(data_get($data, 'choices'), JSON_PRETTY_PRINT)
            );

            throw new PrismStructuredDecodingException($e->getMessage().$context);
        }
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ToolResult[]  $toolResults
     * @param  ToolApprovalRequest[]  $toolApprovalRequests
     */
    protected function addStep(array $data, Request $request, array $toolResults = [], array $toolApprovalRequests = []): void
    {
        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'choices.0.message.content') ?? '',
            finishReason: $this->mapFinishReason($data),
            usage: new Usage(
                (int) data_get($data, 'usage.prompt_tokens', 0),
                (int) data_get($data, 'usage.completion_tokens', 0),
            ),
            meta: new Meta(
                id: data_get($data, 'id', ''),
                model: data_get($data, 'model', $request->model()),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [],
            toolCalls: ToolCallMap::map(data_get($data, 'choices.0.message.tool_calls', [])),
            providerToolCalls: [],
            toolResults: $toolResults,
            toolApprovalRequests: $toolApprovalRequests,
            raw: $data,
        ));
    }
}
