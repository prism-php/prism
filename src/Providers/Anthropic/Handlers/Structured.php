<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Prism\Prism\Contracts\PrismRequest;
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
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use ExtractsCitations, ExtractsText, ExtractsThinking, HandlesHttpRequests, ProcessesRateLimits;

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

        $responseMessage = new AssistantMessage(
            content: $this->tempResponse->text,
            additionalContent: $this->tempResponse->additionalContent
        );

        $this->request->addMessage($responseMessage);
        $this->responseBuilder->addResponseMessage($responseMessage);

        $this->responseBuilder->addStep(new Step(
            text: $this->tempResponse->text,
            structured: $this->tempResponse->structured ?? [],
            finishReason: $this->tempResponse->finishReason,
            usage: $this->tempResponse->usage,
            meta: $this->tempResponse->meta,
            messages: $this->request->messages(),
            systemPrompts: $this->request->systemPrompts(),
            additionalContent: $this->tempResponse->additionalContent,
        ));

        return $this->responseBuilder->toResponse();
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
        ]);

        return $structuredStrategy->mutatePayload($basePayload);
    }

    protected function prepareTempResponse(): void
    {
        $data = $this->httpResponse->json();

        $baseResponse = new Response(
            steps: new Collection,
            responseMessages: new Collection,
            text: $this->extractText($data),
            structured: [],
            finishReason: FinishReasonMap::map(data_get($data, 'stop_reason', '')),
            usage: new Usage(
                promptTokens: data_get($data, 'usage.input_tokens'),
                completionTokens: data_get($data, 'usage.output_tokens'),
                cacheWriteInputTokens: data_get($data, 'usage.cache_creation_input_tokens', null),
                cacheReadInputTokens: data_get($data, 'usage.cache_read_input_tokens', null)
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
                rateLimits: $this->processRateLimits($this->httpResponse)
            ),
            additionalContent: Arr::whereNotNull([
                'messagePartsWithCitations' => $this->extractCitations($data),
                ...$this->extractThinking($data),
            ])
        );

        $this->tempResponse = $this->strategy->mutateResponse($this->httpResponse, $baseResponse);
    }
}
