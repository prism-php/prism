<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers;

use Illuminate\Support\Collection;
use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Providers\Anthropic\Maps\FinishReasonMap;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Structured extends AnthropicHandlerAbstract
{
    /**
     * @var StructuredRequest
     */
    protected PrismRequest $request; // Redeclared for type hinting

    protected Response $tempResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(): Response
    {
        $this->appendMessageForJsonMode();

        $this->sendRequest();

        $this->prepareTempResponse();

        $responseMessage = new AssistantMessage(
            $this->tempResponse->text,
            [],
            $this->tempResponse->additionalContent
        );

        $this->request->addMessage($responseMessage);
        $this->responseBuilder->addResponseMessage($responseMessage);

        $this->responseBuilder->addStep(new Step(
            text: $this->tempResponse->text,
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
            throw new \InvalidArgumentException('Request must be an instance of '.StructuredRequest::class);
        }

        return array_filter([
            'model' => $request->model(),
            'messages' => MessageMap::map($request->messages(), $request->providerMeta(Provider::Anthropic)),
            'system' => MessageMap::mapSystemMessages($request->systemPrompts()),
            'thinking' => $request->providerMeta(Provider::Anthropic, 'thinking.enabled') === true
            ? [
                'type' => 'enabled',
                'budget_tokens' => is_int($request->providerMeta(Provider::Anthropic, 'thinking.budgetTokens'))
                    ? $request->providerMeta(Provider::Anthropic, 'thinking.budgetTokens')
                    : config('prism.anthropic.default_thinking_budget', 1024),
            ]
            : null,
            'max_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
        ]);
    }

    protected function prepareTempResponse(): void
    {
        $data = $this->httpResponse->json();

        $this->tempResponse = new Response(
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
            additionalContent: array_filter([
                'messagePartsWithCitations' => $this->extractCitations($data),
                ...$this->extractThinking($data),
            ])
        );
    }

    protected function appendMessageForJsonMode(): void
    {
        $this->request->addMessage(new UserMessage(sprintf(
            "Respond with ONLY JSON (i.e. not in backticks or a code block, with NO CONTENT outside the JSON) that matches the following schema: \n %s %s",
            json_encode($this->request->schema()->toArray(), JSON_PRETTY_PRINT),
            ($this->request->providerMeta(Provider::Anthropic)['citations'] ?? false)
                ? "\n\n Return the JSON as a single text block with a single set of citations."
                : ''
        )));
    }
}
