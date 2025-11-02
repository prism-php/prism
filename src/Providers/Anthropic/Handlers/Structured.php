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
use Prism\Prism\Providers\Anthropic\Concerns\ExtractsCitations;
use Prism\Prism\Providers\Anthropic\Concerns\ExtractsText;
use Prism\Prism\Providers\Anthropic\Concerns\ExtractsThinking;
use Prism\Prism\Providers\Anthropic\Concerns\HandlesHttpRequests;
use Prism\Prism\Providers\Anthropic\Concerns\ProcessesRateLimits;
use Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies\AnthropicStructuredStrategy;
use Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies\JsonModeStructuredStrategy;
use Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies\ToolStructuredStrategy;
use Prism\Prism\Providers\Anthropic\Maps\FinishReasonMap;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use CallsTools, ExtractsCitations, ExtractsText, ExtractsThinking, HandlesHttpRequests, ProcessesRateLimits;

    protected Response $tempResponse;

    protected ResponseBuilder $responseBuilder;

    protected AnthropicStructuredStrategy $strategy;

    public function __construct(protected PendingRequest $client, protected StructuredRequest $request)
    {
        $this->responseBuilder = new ResponseBuilder;

        $this->strategy = $this->request->providerOptions('use_tool_calling') === true
            ? new ToolStructuredStrategy(request: $request)
            : new JsonModeStructuredStrategy(request: $request);
    }

    public function handle(): Response
    {
        $this->strategy->appendMessages();

        $this->sendRequest();

        $this->prepareTempResponse();

        $toolCalls = $this->extractToolCalls($this->httpResponse->json());

        $responseMessage = new AssistantMessage(
            content: $this->tempResponse->text,
            toolCalls: $toolCalls,
            additionalContent: $this->tempResponse->additionalContent
        );

        $this->request->addMessage($responseMessage);

        return match ($this->tempResponse->finishReason) {
            FinishReason::ToolCalls => $this->handleToolCalls($toolCalls),
            FinishReason::Stop, FinishReason::Length => $this->handleStop(),
            default => throw new \Prism\Prism\Exceptions\PrismException('Anthropic: unknown finish reason'),
        };
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

        $structuredStrategy = $request->providerOptions('use_tool_calling') === true
            ? new ToolStructuredStrategy(request: $request)
            : new JsonModeStructuredStrategy(request: $request);

        $basePayload = Arr::whereNotNull([
            'model' => $request->model(),
            'messages' => MessageMap::map($request->messages(), $request->providerOptions()),
            'system' => MessageMap::mapSystemMessages($request->systemPrompts()) ?: null,
            'thinking' => $request->providerOptions('thinking.enabled') === true
                ? [
                    'type' => 'enabled',
                    'budget_tokens' => is_int($request->providerOptions('thinking.budgetTokens'))
                        ? $request->providerOptions('thinking.budgetTokens')
                        : config('prism.anthropic.default_thinking_budget', 1024),
                ]
                : null,
            'max_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'mcp_servers' => $request->providerOptions('mcp_servers'),
        ]);

        return $structuredStrategy->mutatePayload($basePayload);
    }

    /**
     * @param  ToolCall[]  $toolCalls
     */
    protected function handleToolCalls(array $toolCalls): Response
    {
        $hasCustomTools = $this->hasCustomToolCalls($toolCalls);
        $hasStructuredTool = $this->hasStructuredToolCall($toolCalls);

        if ($hasCustomTools) {
            $customToolCalls = array_filter(
                $toolCalls,
                fn (ToolCall $call): bool => $call->name !== 'output_structured_data'
            );

            $toolResults = $this->callTools($this->request->tools(), $customToolCalls);

            // If structured tool was also called alongside custom tools, we're done
            // Extract the data and return immediately
            if ($hasStructuredTool) {
                $this->addStep($toolCalls, $toolResults);

                return $this->responseBuilder->toResponse();
            }

            // Only custom tools were called, recurse to get structured output
            $message = new ToolResultMessage($toolResults);
            if ($tool_result_cache_type = $this->request->providerOptions('tool_result_cache_type')) {
                $message->withProviderOptions(['cacheType' => $tool_result_cache_type]);
            }

            $this->request->addMessage($message);

            $this->addStep($toolCalls, $toolResults);

            if ($this->responseBuilder->steps->count() < $this->request->maxSteps()) {
                return $this->handle();
            }

            return $this->responseBuilder->toResponse();
        }

        if ($hasStructuredTool) {
            $this->addStep($toolCalls);

            return $this->responseBuilder->toResponse();
        }

        $this->addStep($toolCalls);

        return $this->responseBuilder->toResponse();
    }

    protected function handleStop(): Response
    {
        $this->addStep();

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  ToolCall[]  $toolCalls
     */
    protected function hasCustomToolCalls(array $toolCalls): bool
    {
        foreach ($toolCalls as $call) {
            if ($call->name !== 'output_structured_data') {
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
            if ($call->name === 'output_structured_data') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(array $toolCalls = [], array $toolResults = []): void
    {
        // A step is a structured step if:
        // - There are no custom tools (only structured tool or no tools)
        // - OR there are no tool results (initial step)
        // - OR the structured tool is present (even with custom tools)
        $isStructuredStep = ! $this->hasCustomToolCalls($toolCalls)
            || $toolResults === []
            || $this->hasStructuredToolCall($toolCalls);

        $this->responseBuilder->addStep(new Step(
            text: $this->tempResponse->text,
            finishReason: $this->tempResponse->finishReason,
            usage: $this->tempResponse->usage,
            meta: $this->tempResponse->meta,
            messages: $this->request->messages(),
            systemPrompts: $this->request->systemPrompts(),
            additionalContent: $this->tempResponse->additionalContent,
            structured: $isStructuredStep ? ($this->tempResponse->structured ?? []) : [],
            toolCalls: $toolCalls,
            toolResults: $toolResults,
        ));
    }

    protected function prepareTempResponse(): void
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
            additionalContent: Arr::whereNotNull([
                'citations' => $this->extractCitations($data),
                ...$this->extractThinking($data),
            ])
        );

        $this->tempResponse = $this->strategy->mutateResponse($this->httpResponse, $baseResponse);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return ToolCall[]
     */
    protected function extractToolCalls(array $data): array
    {
        $toolCalls = [];
        $contents = data_get($data, 'content', []);

        foreach ($contents as $content) {
            if (data_get($content, 'type') === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: data_get($content, 'id'),
                    name: data_get($content, 'name'),
                    arguments: data_get($content, 'input')
                );
            }
        }

        return $toolCalls;
    }
}
