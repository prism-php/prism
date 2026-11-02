<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
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
use Prism\Prism\Providers\Anthropic\Maps\FinishReasonMap;
use Prism\Prism\Providers\Anthropic\Maps\RequestPayloadMap;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Text
{
    use CallsTools, ExtractsAdditionalContent, ExtractsProviderToolCalls, ExtractsText, HandlesHttpRequests, ProcessesRateLimits, ResolvesThinking;

    protected Response $tempResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client, protected TextRequest $request)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(): Response
    {
        $this->resolveToolApprovals($this->request);

        $this->sendRequest();

        $this->prepareTempResponse();

        return match ($this->tempResponse->finishReason) {
            FinishReason::ToolCalls => $this->tempResponse->toolCalls === []
                ? $this->handleStop()
                : $this->handleToolCalls(),
            FinishReason::Pause => $this->handlePause(),
            // Refusal and unknown reasons resolve gracefully (prism-php/prism#996);
            // the response carries the mapped finish reason for the caller.
            default => $this->handleStop(),
        };
    }

    /**
     * @param  TextRequest  $request
     * @return array<string, mixed>
     */
    #[\Override]
    public static function buildHttpRequestPayload(PrismRequest $request): array
    {
        if (! $request->is(TextRequest::class)) {
            throw new InvalidArgumentException('Request must be an instance of '.TextRequest::class);
        }

        return RequestPayloadMap::text($request, static::buildTools($request), static::resolveThinking($request));
    }

    /**
     * Anthropic returns stop_reason="pause_turn" when a long-running server-side
     * tool (e.g. web_search, web_fetch) needs the client to continue the turn.
     * Per Anthropic's docs, the client should append the assistant message to
     * the conversation and re-send the request unchanged so the model can resume.
     */
    protected function handlePause(): Response
    {
        $this->addStep();

        $this->request->addMessage(new AssistantMessage(
            $this->tempResponse->text,
            $this->tempResponse->toolCalls,
            $this->tempResponse->additionalContent,
        ));

        if ($this->responseBuilder->steps->count() < $this->request->maxSteps()) {
            return $this->handle();
        }

        return $this->responseBuilder->toResponse();
    }

    protected function handleToolCalls(): Response
    {
        $hasPendingToolCalls = false;
        $approvalRequests = [];
        $toolResults = $this->callToolsWithPending($this->request->tools(), $this->tempResponse->toolCalls, $hasPendingToolCalls, $approvalRequests);

        $this->addStep($toolResults, $approvalRequests);

        $this->request->addMessage(new AssistantMessage(
            $this->tempResponse->text,
            $this->tempResponse->toolCalls,
            $this->tempResponse->additionalContent,
            $approvalRequests,
        ));

        $toolResultMessage = new ToolResultMessage($toolResults);

        $this->request->addMessage($toolResultMessage);
        $this->request->resetToolChoice();

        if (! $hasPendingToolCalls && $this->responseBuilder->steps->count() < $this->request->maxSteps()) {
            return $this->handle();
        }

        return $this->responseBuilder->toResponse();
    }

    protected function handleStop(): Response
    {
        $this->addStep();

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  ToolResult[]  $toolResults
     * @param  ToolApprovalRequest[]  $toolApprovalRequests
     */
    protected function addStep(array $toolResults = [], array $toolApprovalRequests = []): void
    {
        $data = $this->httpResponse->json();

        $this->responseBuilder->addStep(new Step(
            text: $this->tempResponse->text,
            finishReason: $this->tempResponse->finishReason,
            toolCalls: $this->tempResponse->toolCalls,
            toolResults: $toolResults,
            providerToolCalls: $this->extractProviderToolCalls($data),
            usage: $this->tempResponse->usage,
            meta: $this->tempResponse->meta,
            messages: $this->request->messages(),
            systemPrompts: $this->request->systemPrompts(),
            additionalContent: $this->tempResponse->additionalContent,
            raw: $data,
            toolApprovalRequests: $toolApprovalRequests,
        ));
    }

    protected function prepareTempResponse(): void
    {
        $data = $this->httpResponse->json();

        $this->tempResponse = new Response(
            steps: new Collection,
            text: $this->extractText($data),
            finishReason: FinishReasonMap::map(data_get($data, 'stop_reason', '')),
            toolCalls: $this->extractToolCalls($data),
            toolResults: [],
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
            messages: new Collection,
            additionalContent: Arr::whereNotNull([
                ...$this->extractAdditionalContent($data),
                // What the server actually cleared (#35). Without it a caller
                // cannot tell "cleared 40 tool results" from "the beta header
                // was ignored" -- both look identical from outside: a
                // successful response and a smaller bill nobody can attribute.
                'context_management' => data_get($data, 'context_management'),
            ])
        );
    }

    /**
     * @return array<int|string,mixed>
     */
    protected static function buildTools(TextRequest $request): array
    {
        return RequestPayloadMap::buildTools($request);
    }
}
