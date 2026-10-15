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
use Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies\AnthropicStructuredStrategy;
use Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies\NativeOutputFormatStructuredStrategy;
use Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies\ToolStructuredStrategy;
use Prism\Prism\Providers\Anthropic\Maps\FinishReasonMap;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap;
use Prism\Prism\Providers\Anthropic\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Anthropic\Maps\ToolMap;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderTool;
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

        $structuredStrategy = self::createStrategy($request);

        $basePayload = Arr::whereNotNull([
            'model' => $request->model(),
            'messages' => MessageMap::map($request->messages(), $request->providerOptions()),
            'system' => MessageMap::mapSystemMessages($request->systemPrompts()) ?: null,
            'thinking' => static::resolveThinking($request),
            'max_tokens' => $request->maxTokens() ?? 64000,
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'tools' => static::buildTools($request) ?: null,
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            'mcp_servers' => $request->providerOptions('mcp_servers'),
            'cache_control' => $request->providerOptions('cache_control'),
            'output_config' => $request->providerOptions('effort') !== null
                ? ['effort' => $request->providerOptions('effort')]
                : null,
            'fallbacks' => $request->providerOptions('fallbacks'),
        ]);

        return $structuredStrategy->mutatePayload($basePayload);
    }

    protected static function createStrategy(StructuredRequest $request): AnthropicStructuredStrategy
    {
        return $request->providerOptions('use_tool_calling')
            ? new ToolStructuredStrategy(request: $request)
            : new NativeOutputFormatStructuredStrategy(request: $request);
    }

    /**
     * @return array<int|string, mixed>
     */
    protected static function buildTools(StructuredRequest $request): array
    {
        $tools = ToolMap::map($request->tools());

        if ($request->providerTools() === []) {
            return $tools;
        }

        $providerTools = array_map(
            fn (ProviderTool $tool): array => [
                'type' => $tool->type,
                'name' => $tool->name,
                ...$tool->options,
            ],
            $request->providerTools()
        );

        return array_merge($providerTools, $tools);
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
        $toolResults = $this->callTools($this->request->tools(), $customToolCalls);
        $this->addStep($toolCalls, $tempResponse, $toolResults);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  ToolCall[]  $toolCalls
     */
    protected function executeCustomToolsAndContinue(array $toolCalls, Response $tempResponse): Response
    {
        $customToolCalls = $this->filterCustomToolCalls($toolCalls);
        $toolResults = $this->callTools($this->request->tools(), $customToolCalls);

        $this->request->addMessage(new ToolResultMessage($toolResults));
        $this->request->resetToolChoice();
        $this->addStep($toolCalls, $tempResponse, $toolResults);

        if ($this->canContinue()) {
            return $this->handle();
        }

        return $this->responseBuilder->toResponse();
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
     */
    protected function addStep(array $toolCalls, Response $tempResponse, array $toolResults = []): void
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
                cacheReadInputTokens: data_get($data, 'usage.cache_read_input_tokens')
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
