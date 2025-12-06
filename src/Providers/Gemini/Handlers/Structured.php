<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Concerns\HandlesStructuredJson;
use Prism\Prism\Concerns\ManagesStructuredSteps;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Gemini\Concerns\ValidatesResponse;
use Prism\Prism\Providers\Gemini\Maps\FinishReasonMap;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
use Prism\Prism\Providers\Gemini\Maps\SchemaMap;
use Prism\Prism\Providers\Gemini\Maps\ToolCallMap;
use Prism\Prism\Providers\Gemini\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Gemini\Maps\ToolMap;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use CallsTools;
    use HandlesStructuredJson;
    use ManagesStructuredSteps;
    use ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $data = $this->sendRequest($request);

        $this->validateResponse($data);

        $isToolCall = ! empty(data_get($data, 'candidates.0.content.parts.0.functionCall'));

        $responseMessage = new AssistantMessage(
            data_get($data, 'candidates.0.content.parts.0.text') ?? '',
            $isToolCall ? ToolCallMap::map(data_get($data, 'candidates.0.content.parts', [])) : [],
        );

        $request->addMessage($responseMessage);

        $finishReason = FinishReasonMap::map(
            data_get($data, 'candidates.0.finishReason'),
            $isToolCall
        );

        return match ($finishReason) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request),
            FinishReason::Stop, FinishReason::Length => $this->handleStop($data, $request, $finishReason),
            default => throw new PrismException('Gemini: unhandled finish reason'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function sendRequest(Request $request): array
    {
        $providerOptions = $request->providerOptions();

        if ($request->tools() !== [] && $request->providerTools() !== []) {
            throw new PrismException('Use of provider tools with custom tools is not currently supported by Gemini.');
        }

        $tools = [];

        if ($request->providerTools() !== []) {
            $tools = [
                Arr::mapWithKeys(
                    $request->providerTools(),
                    fn (ProviderTool $providerTool): array => [
                        $providerTool->type => $providerTool->options !== [] ? $providerTool->options : (object) [],
                    ]
                ),
            ];
        }

        if ($request->tools() !== []) {
            $tools = [
                [
                    'function_declarations' => ToolMap::map($request->tools()),
                ],
            ];
        }

        $thinkingConfig = $providerOptions['thinkingConfig'] ?? null;

        if (isset($providerOptions['thinkingBudget'])) {
            $thinkingConfig = Arr::whereNotNull([
                'thinkingBudget' => $providerOptions['thinkingBudget'],
                'includeThoughts' => $providerOptions['includeThoughts'] ?? null,
            ]);
        }

        if (isset($providerOptions['thinkingLevel'])) {
            $thinkingConfig = Arr::whereNotNull([
                'thinkingLevel' => $providerOptions['thinkingLevel'],
                'includeThoughts' => $providerOptions['includeThoughts'] ?? null,
            ]);
        }

        /** @var \Illuminate\Http\Client\Response $response */
        $response = $this->client->post(
            "{$request->model()}:generateContent",
            Arr::whereNotNull([
                ...(new MessageMap($request->messages(), $request->systemPrompts()))(),
                'cachedContent' => $providerOptions['cachedContentName'] ?? null,
                'generationConfig' => Arr::whereNotNull([
                    'response_mime_type' => 'application/json',
                    'response_schema' => (new SchemaMap($request->schema()))->toArray(),
                    'temperature' => $request->temperature(),
                    'topP' => $request->topP(),
                    'maxOutputTokens' => $request->maxTokens(),
                    'thinkingConfig' => $thinkingConfig,
                ]),
                'tools' => $tools !== [] ? $tools : null,
                'tool_config' => $request->toolChoice() ? ToolChoiceMap::map($request->toolChoice()) : null,
                'safetySettings' => $providerOptions['safetySettings'] ?? null,
            ])
        );

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateResponse(array $data): void
    {
        if (! $data || data_get($data, 'error')) {
            throw PrismException::providerResponseError(vsprintf(
                'Gemini Error: [%s] %s',
                [
                    data_get($data, 'error.code', 'unknown'),
                    data_get($data, 'error.message', 'unknown'),
                ]
            ));
        }

        $finishReason = data_get($data, 'candidates.0.finishReason');
        $content = data_get($data, 'candidates.0.content.parts.0.text', '');
        $thoughtTokens = data_get($data, 'usageMetadata.thoughtsTokenCount', 0);

        if ($finishReason === 'MAX_TOKENS') {
            $promptTokens = data_get($data, 'usageMetadata.promptTokenCount', 0);
            $candidatesTokens = data_get($data, 'usageMetadata.candidatesTokenCount', 0);
            $totalTokens = data_get($data, 'usageMetadata.totalTokenCount', 0);
            $outputTokens = $candidatesTokens - $thoughtTokens;

            $isEmpty = in_array(trim((string) $content), ['', '0'], true);
            $isInvalidJson = ! empty($content) && json_decode((string) $content) === null;
            $contentLength = strlen((string) $content);

            if (($isEmpty || $isInvalidJson) && $thoughtTokens > 0) {
                $errorDetail = $isEmpty
                    ? 'no tokens remained for structured output'
                    : "output was truncated at {$contentLength} characters resulting in invalid JSON";

                throw PrismException::providerResponseError(
                    'Gemini hit token limit with high thinking token usage. '.
                    "Token usage: {$promptTokens} prompt + {$thoughtTokens} thinking + {$outputTokens} output = {$totalTokens} total. ".
                    "The {$errorDetail}. ".
                    'Try increasing maxTokens to at least '.($totalTokens + 1000).' (suggested: '.($totalTokens * 2).' for comfortable margin).'
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleStop(array $data, Request $request, FinishReason $finishReason): StructuredResponse
    {
        $this->addStep($data, $request, $finishReason);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request): StructuredResponse
    {
        $toolResults = $this->callTools(
            $request->tools(),
            ToolCallMap::map(data_get($data, 'candidates.0.content.parts', []))
        );

        $request->addMessage(new ToolResultMessage($toolResults));

        $this->addStep($data, $request, FinishReason::ToolCalls, $toolResults);

        if ($this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(array $data, Request $request, FinishReason $finishReason, array $toolResults = []): void
    {
        $isStructuredStep = $finishReason !== FinishReason::ToolCalls;

        $this->responseBuilder->addStep(
            new Step(
                text: data_get($data, 'candidates.0.content.parts.0.text') ?? '',
                finishReason: $finishReason,
                usage: new Usage(
                    promptTokens: data_get($data, 'usageMetadata.promptTokenCount', 0),
                    completionTokens: data_get($data, 'usageMetadata.candidatesTokenCount', 0),
                    cacheReadInputTokens: data_get($data, 'usageMetadata.cachedContentTokenCount'),
                    thoughtTokens: data_get($data, 'usageMetadata.thoughtsTokenCount'),
                ),
                meta: new Meta(
                    id: data_get($data, 'id', ''),
                    model: data_get($data, 'modelVersion', ''),
                ),
                messages: $request->messages(),
                systemPrompts: $request->systemPrompts(),
                structured: $isStructuredStep ? $this->extractStructuredData(data_get($data, 'candidates.0.content.parts.0.text') ?? '') : [],
                toolCalls: $finishReason === FinishReason::ToolCalls ? ToolCallMap::map(data_get($data, 'candidates.0.content.parts', [])) : [],
                toolResults: $toolResults,
            )
        );
    }
}
