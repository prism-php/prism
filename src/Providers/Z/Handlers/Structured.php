<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Z\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Prism\Prism\Providers\Z\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Z\Maps\StructuredMap;
use Prism\Prism\Providers\Z\Maps\ToolCallMap;
use Prism\Prism\Providers\Z\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Z\Maps\ToolMap;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use CallsTools;
    use MapsFinishReason;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $this->resolveToolApprovals($request);

        $response = $this->sendRequest($request);

        $data = $response->json();

        return match ($this->mapFinishReason($data)) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request),
            FinishReason::Stop, FinishReason::Length => $this->handleStop($data, $request),
            default => throw new PrismException('Z: unknown finish reason'),
        };
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

        $this->addStep($data, $request, $toolResults, $approvalRequests);

        $request = $request->addMessage(new AssistantMessage(
            data_get($data, 'choices.0.message.content') ?? '',
            $toolCalls,
            [],
            $approvalRequests,
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

    protected function sendRequest(Request $request): ClientResponse
    {
        $structured = new StructuredMap($request->messages(), $request->systemPrompts(), $request->schema());

        $payload = array_merge([
            'model' => $request->model(),
            'messages' => $structured(),
            'response_format' => [
                'type' => 'json_object',
            ],
            'thinking' => [
                'type' => 'disabled',
            ],
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'max_tokens' => $request->maxTokens(),
            'tools' => ToolMap::map($request->tools()),
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
        ]));

        /** @var ClientResponse $response */
        $response = $this->client->post('chat/completions', $payload);

        return $response;
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
                promptTokens: data_get($data, 'usage.prompt_tokens', 0),
                completionTokens: data_get($data, 'usage.completion_tokens', 0),
            ),
            meta: new Meta(
                id: data_get($data, 'id', ''),
                model: data_get($data, 'model', $request->model()),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [],
            structured: [],
            toolCalls: ToolCallMap::map(data_get($data, 'choices.0.message.tool_calls', [])),
            providerToolCalls: [],
            toolResults: $toolResults,
            toolApprovalRequests: $toolApprovalRequests,
            raw: $data,
        ));
    }
}
