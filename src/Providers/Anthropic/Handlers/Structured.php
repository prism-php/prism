<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Anthropic\Concerns\ExtractsAdditionalContent;
use Prism\Prism\Providers\Anthropic\Concerns\ExtractsProviderToolCalls;
use Prism\Prism\Providers\Anthropic\Concerns\ExtractsText;
use Prism\Prism\Providers\Anthropic\Concerns\HandlesHttpRequests;
use Prism\Prism\Providers\Anthropic\Concerns\ProcessesRateLimits;
use Prism\Prism\Providers\Anthropic\Concerns\ResolvesThinking;
use Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies\AnthropicStructuredStrategy;
use Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies\ToolStructuredStrategy;
use Prism\Prism\Providers\Anthropic\Maps\FinishReasonMap;
use Prism\Prism\Providers\Anthropic\Maps\RequestPayloadMap;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response;
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
    use CallsTools, ExtractsAdditionalContent, ExtractsProviderToolCalls, ExtractsText, HandlesHttpRequests, ProcessesRateLimits, ResolvesThinking;

    protected ResponseBuilder $responseBuilder;

    protected AnthropicStructuredStrategy $strategy;

    public function __construct(protected PendingRequest $client, protected StructuredRequest $request)
    {
        $this->responseBuilder = new ResponseBuilder;
        $this->strategy = self::createStrategy($request);
    }

    public function handle(): Response
    {
        $this->resolveToolApprovals($this->request);

        if ($this->responseBuilder->steps->isEmpty()) {
            $this->strategy->appendMessages();
        }

        $this->sendRequest();

        $data = $this->httpResponse->json();

        $tempResponse = $this->buildTempResponse();

        $toolCalls = $this->extractToolCalls($data);

        $this->request->addMessage(new AssistantMessage(
            content: $this->extractText($data),
            toolCalls: $toolCalls,
            additionalContent: $tempResponse->additionalContent
        ));

        if ($tempResponse->finishReason === FinishReason::ToolCalls && $toolCalls !== []) {
            return $this->handleToolCalls($toolCalls, $tempResponse);
        }

        if ($tempResponse->finishReason === FinishReason::Pause) {
            return $this->handlePause($tempResponse);
        }

        return $this->handleStop($tempResponse);
    }

    /**
     * @param  StructuredRequest  $request
     * @return array<string, mixed>
     */
    #[\Override]
    public static function buildHttpRequestPayload(PrismRequest $request): array
    {
        if (! $request->is(StructuredRequest::class)) {
            throw new InvalidArgumentException('Request must be an instance of '.StructuredRequest::class);
        }

        return RequestPayloadMap::structured($request, static::buildTools($request), static::resolveThinking($request));
    }

    protected static function createStrategy(StructuredRequest $request): AnthropicStructuredStrategy
    {
        return RequestPayloadMap::createStrategy($request);
    }

    /**
     * @return array<int|string, mixed>
     */
    protected static function buildTools(StructuredRequest $request): array
    {
        return RequestPayloadMap::buildTools($request);
    }

    /**
     * @param  ToolCall[]  $toolCalls
     */
    protected function handleToolCalls(array $toolCalls, Response $tempResponse): Response
    {
        $hasCustomTools = $this->hasCustomToolCalls($toolCalls);
        $hasStructuredTool = $this->hasStructuredToolCall($toolCalls);

        if ($hasCustomTools && $hasStructuredTool) {
            return $this->executeCustomToolsAndFinalize($toolCalls, $tempResponse);
        }

        if ($hasCustomTools) {
            return $this->executeCustomToolsAndContinue($toolCalls, $tempResponse);
        }

        return $this->finalizeWithToolCalls($toolCalls, $tempResponse);
    }

    /**
     * @param  ToolCall[]  $toolCalls
     */
    protected function executeCustomToolsAndFinalize(array $toolCalls, Response $tempResponse): Response
    {
        $customToolCalls = $this->filterCustomToolCalls($toolCalls);
        $hasPendingToolCalls = false;
        $approvalRequests = [];
        $toolResults = $this->callToolsWithPending($this->request->tools(), $customToolCalls, $hasPendingToolCalls, $approvalRequests);

        $this->attachApprovalRequests($approvalRequests, $tempResponse, $toolCalls);
        $this->addStep($toolCalls, $tempResponse, $toolResults, $approvalRequests);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  ToolCall[]  $toolCalls
     */
    protected function executeCustomToolsAndContinue(array $toolCalls, Response $tempResponse): Response
    {
        $customToolCalls = $this->filterCustomToolCalls($toolCalls);
        $hasPendingToolCalls = false;
        $approvalRequests = [];
        $toolResults = $this->callToolsWithPending($this->request->tools(), $customToolCalls, $hasPendingToolCalls, $approvalRequests);

        $this->attachApprovalRequests($approvalRequests, $tempResponse, $toolCalls);

        $this->request->addMessage(new ToolResultMessage($toolResults));
        $this->request->resetToolChoice();
        $this->addStep($toolCalls, $tempResponse, $toolResults, $approvalRequests);

        if (! $hasPendingToolCalls && $this->canContinue()) {
            return $this->handle();
        }

        return $this->responseBuilder->toResponse();
    }

    /**
     * Replace the already-appended assistant message with one carrying the
     * approval requests, so the resume pass can correlate them.
     *
     * @param  ToolApprovalRequest[]  $approvalRequests
     * @param  ToolCall[]  $toolCalls
     */
    protected function attachApprovalRequests(array $approvalRequests, Response $tempResponse, array $toolCalls): void
    {
        if ($approvalRequests === []) {
            return;
        }

        $messages = $this->request->messages();
        array_pop($messages);
        $this->request->setMessages($messages);

        $this->request->addMessage(new AssistantMessage(
            content: $tempResponse->text,
            toolCalls: $toolCalls,
            additionalContent: $tempResponse->additionalContent,
            toolApprovalRequests: $approvalRequests,
        ));
    }

    /**
     * @param  ToolCall[]  $toolCalls
     */
    protected function finalizeWithToolCalls(array $toolCalls, Response $tempResponse): Response
    {
        $this->addStep($toolCalls, $tempResponse);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @return ToolCall[]
     */
    protected function filterCustomToolCalls(array $toolCalls): array
    {
        return array_filter(
            $toolCalls,
            fn (ToolCall $call): bool => $call->name !== ToolStructuredStrategy::STRUCTURED_OUTPUT_TOOL_NAME
        );
    }

    protected function canContinue(): bool
    {
        return $this->responseBuilder->steps->count() < $this->request->maxSteps();
    }

    /**
     * A long-running server tool paused the turn (`pause_turn`). The paused
     * assistant content is already on the request, so re-sending it lets the
     * model resume the same turn.
     */
    protected function handlePause(Response $tempResponse): Response
    {
        $this->addStep([], $tempResponse);

        if ($this->canContinue()) {
            return $this->handle();
        }

        return $this->responseBuilder->toResponse();
    }

    protected function handleStop(Response $tempResponse): Response
    {
        $this->addStep([], $tempResponse);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  ToolCall[]  $toolCalls
     */
    protected function hasCustomToolCalls(array $toolCalls): bool
    {
        foreach ($toolCalls as $call) {
            if ($call->name !== ToolStructuredStrategy::STRUCTURED_OUTPUT_TOOL_NAME) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  ToolCall[]  $toolCalls
     */
    protected function hasStructuredToolCall(array $toolCalls): bool
    {
        foreach ($toolCalls as $call) {
            if ($call->name === ToolStructuredStrategy::STRUCTURED_OUTPUT_TOOL_NAME) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  ToolApprovalRequest[]  $toolApprovalRequests
     */
    protected function addStep(array $toolCalls, Response $tempResponse, array $toolResults = [], array $toolApprovalRequests = []): void
    {
        $data = $this->httpResponse->json();
        $isStructuredStep = $this->determineIfStructuredStep($toolCalls, $toolResults);

        $this->responseBuilder->addStep(new Step(
            text: $tempResponse->text,
            finishReason: $tempResponse->finishReason,
            usage: $tempResponse->usage,
            meta: $tempResponse->meta,
            messages: $this->request->messages(),
            systemPrompts: $this->request->systemPrompts(),
            additionalContent: $tempResponse->additionalContent,
            structured: $isStructuredStep ? ($tempResponse->structured ?? []) : [],
            toolCalls: $toolCalls,
            providerToolCalls: $this->extractProviderToolCalls($data),
            toolResults: $toolResults,
            raw: $data,
            toolApprovalRequests: $toolApprovalRequests,
        ));
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     */
    protected function determineIfStructuredStep(array $toolCalls, array $toolResults): bool
    {
        if ($this->hasOnlyStructuredTools($toolCalls)) {
            return true;
        }
        if ($this->isInitialStep($toolResults)) {
            return true;
        }

        return $this->includesStructuredTool($toolCalls);
    }

    /**
     * @param  ToolCall[]  $toolCalls
     */
    protected function hasOnlyStructuredTools(array $toolCalls): bool
    {
        return ! $this->hasCustomToolCalls($toolCalls);
    }

    /**
     * @param  ToolResult[]  $toolResults
     */
    protected function isInitialStep(array $toolResults): bool
    {
        return $toolResults === [];
    }

    /**
     * @param  ToolCall[]  $toolCalls
     */
    protected function includesStructuredTool(array $toolCalls): bool
    {
        return $this->hasStructuredToolCall($toolCalls);
    }

    protected function buildTempResponse(): Response
    {
        $data = $this->httpResponse->json();

        $baseResponse = new Response(
            steps: new Collection,
            text: $this->extractText($data),
            structured: [],
            finishReason: FinishReasonMap::map(data_get($data, 'stop_reason', '')),
            usage: new Usage(
                promptTokens: data_get($data, 'usage.input_tokens'),
                completionTokens: data_get($data, 'usage.output_tokens'),
                cacheWriteInputTokens: data_get($data, 'usage.cache_creation_input_tokens'),
                cacheReadInputTokens: data_get($data, 'usage.cache_read_input_tokens'),
                thoughtTokens: data_get($data, 'usage.output_tokens_details.thinking_tokens'),
                cacheWrite5mInputTokens: data_get($data, 'usage.cache_creation.ephemeral_5m_input_tokens'),
                cacheWrite1hInputTokens: data_get($data, 'usage.cache_creation.ephemeral_1h_input_tokens'),
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
                rateLimits: $this->processRateLimits($this->httpResponse)
            ),
            additionalContent: $this->extractAdditionalContent($data)
        );

        return $this->strategy->mutateResponse($this->httpResponse, $baseResponse);
    }
}
