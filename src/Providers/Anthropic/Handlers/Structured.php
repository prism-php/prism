<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\Exceptions\PrismException;
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
        $this->validateProviderOptions();

        if ($this->shouldUseToolCalling()) {
            if ($this->request->providerOptions('thinking.enabled') === true) {
                // In thinking mode with tools, add guidance for JSON output
                $this->appendThinkingModeGuidance();
            }
        } else {
            $this->appendMessageForJsonMode();
        }

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

        // Override the structured data if using tool calling
        if ($this->shouldUseToolCalling()) {
            $response = $this->responseBuilder->toResponse();

            return new Response(
                steps: $response->steps,
                responseMessages: $response->responseMessages,
                text: $response->text,
                structured: $this->tempResponse->structured,
                finishReason: $response->finishReason,
                usage: $response->usage,
                meta: $response->meta,
                additionalContent: $response->additionalContent
            );
        }

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

        // Validate options
        if ($request->providerOptions('citations') === true && $request->providerOptions('use_tool_calling') === true) {
            throw new PrismException(
                'Citations are not supported with tool calling mode. '.
                'Please set use_tool_calling to false in provider options to use citations.'
            );
        }

        if ($request->providerOptions('use_tool_calling') === true) {
            return static::buildToolCallingPayload($request);
        }

        return Arr::whereNotNull([
            'model' => $request->model(),
            'messages' => MessageMap::map($request->messages(), $request->providerOptions()),
            'system' => MessageMap::mapSystemMessages($request->systemPrompts()),
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
    }

    /**
     * @return array<string, mixed>
     */
    protected static function buildToolCallingPayload(StructuredRequest $request): array
    {
        // Use the schema directly for tool input_schema instead of converting to Tool parameters
        $schemaArray = $request->schema()->toArray();
        $isThinkingEnabled = $request->providerOptions('thinking.enabled') === true;

        $properties = $schemaArray['properties'];
        $required = $schemaArray['required'] ?? [];

        // Prepend thinking fields when thinking mode is enabled (using special characters to avoid conflicts)
        if ($isThinkingEnabled) {
            $thinkingProperties = [
                '__thinking' => [
                    'type' => 'string',
                    'description' => 'Your step-by-step thinking process and reasoning before the final answer',
                ],
            ];

            // Prepend thinking fields to the beginning of properties
            $properties = $thinkingProperties + $properties;

            // Prepend thinking fields to required array
            $required = array_merge(['__thinking'], $required);
        }

        $toolDefinition = [
            'name' => 'output_structured_data',
            'description' => 'Output data in the requested structure',
            'input_schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
                'additionalProperties' => false,
            ],
        ];

        return Arr::whereNotNull([
            'model' => $request->model(),
            'messages' => MessageMap::map($request->messages(), $request->providerOptions()),
            'system' => MessageMap::mapSystemMessages($request->systemPrompts()),
            'thinking' => $isThinkingEnabled
            ? [
                'type' => 'enabled',
                'budget_tokens' => is_int($request->providerOptions('thinking.budgetTokens'))
                    ? $request->providerOptions('thinking.budgetTokens')
                    : config('prism.anthropic.default_thinking_budget', 1024),
            ]
            : null,
            'tools' => [$toolDefinition],
            // Don't force tool choice when thinking is enabled
            'tool_choice' => $isThinkingEnabled ? null : ['type' => 'tool', 'name' => 'output_structured_data'],
            'max_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
        ]);
    }

    protected function prepareTempResponse(): void
    {
        $data = $this->httpResponse->json();

        if ($this->shouldUseToolCalling()) {
            // Extract structured data from tool calls
            $structuredData = [];
            /** @var array<int, array<string, mixed>> $contentArray */
            $contentArray = data_get($data, 'content', []);
            $toolCalls = collect($contentArray)
                ->filter(fn ($content): bool => data_get($content, 'type') === 'tool_use')
                ->filter(fn ($toolUse): bool => data_get($toolUse, 'name') === 'output_structured_data');

            if ($toolCalls->isNotEmpty()) {
                $structuredData = data_get($toolCalls->first(), 'input', []);
            } else {
                // Fallback: if no tool was used (e.g., in thinking mode), try to parse JSON from text
                $text = $this->extractText($data);
                try {
                    $parsedJson = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
                    if (is_array($parsedJson)) {
                        $structuredData = $parsedJson;
                    }
                } catch (\JsonException) {
                    // If JSON parsing fails, leave structured data empty
                }
            }

            // Citations isn't available in tool calling mode
            $additionalContent = Arr::whereNotNull([
                ...$this->extractThinking($data),
            ]);

            // If thinking mode was enabled and we have __thinking in structured data,
            // also add it to additionalContent for backward compatibility
            if ($this->request->providerOptions('thinking.enabled') === true &&
                isset($structuredData['__thinking'])) {
                $additionalContent['thinking'] = $structuredData['__thinking'];

                // Remove thinking fields from structured data
                unset($structuredData['__thinking']);
            }

            $this->tempResponse = new Response(
                steps: new Collection,
                responseMessages: new Collection,
                text: $this->extractText($data),
                structured: $structuredData,
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
                additionalContent: $additionalContent
            );
        } else {
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
                additionalContent: Arr::whereNotNull([
                    'messagePartsWithCitations' => $this->extractCitations($data),
                    ...$this->extractThinking($data),
                ])
            );
        }
    }

    protected function appendMessageForJsonMode(): void
    {
        $this->request->addMessage(new UserMessage(sprintf(
            "Respond with ONLY JSON (i.e. not in backticks or a code block, with NO CONTENT outside the JSON) that matches the following schema: \n %s %s",
            json_encode($this->request->schema()->toArray(), JSON_PRETTY_PRINT),
            ($this->request->providerOptions()['citations'] ?? false)
                ? "\n\n Return the JSON as a single text block with a single set of citations."
                : ''
        )));
    }

    protected function shouldUseToolCalling(): bool
    {
        return $this->request->providerOptions('use_tool_calling') === true;
    }

    protected function validateProviderOptions(): void
    {
        if ($this->request->providerOptions('citations') === true && $this->shouldUseToolCalling()) {
            throw new PrismException(
                'Citations are not supported with tool calling mode. '.
                'Please set use_tool_calling to false in provider options to use citations.'
            );
        }
    }

    protected function appendThinkingModeGuidance(): void
    {
        $this->request->addMessage(new UserMessage(sprintf(
            "Please use the output_structured_data tool to provide your response. If for any reason you cannot use the tool, respond with ONLY JSON that matches this schema: \n %s",
            json_encode($this->request->schema()->toArray(), JSON_PRETTY_PRINT)
        )));
    }
}
