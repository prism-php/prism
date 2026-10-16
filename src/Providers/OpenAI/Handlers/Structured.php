<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Concerns\HandlesStructuredJson;
use Prism\Prism\Concerns\ManagesStructuredSteps;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenAI\Concerns\ExtractsCitations;
use Prism\Prism\Providers\OpenAI\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenAI\Concerns\MapsUsage;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolCallMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolMap;
use Prism\Prism\Providers\OpenAI\Support\OutputItems;
use Prism\Prism\Providers\OpenAI\Support\StructuredModeResolver;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\ValueObjects\ToolResult;

class Structured
{
    use CallsTools;
    use ExtractsCitations;
    use HandlesStructuredJson;
    use ManagesStructuredSteps;
    use MapsFinishReason;
    use MapsUsage;
    use ProcessRateLimits;
    use ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $response = match ($request->mode()) {
            StructuredMode::Auto => $this->handleAutoMode($request),
            StructuredMode::Structured => $this->handleStructuredMode($request),
            StructuredMode::Json => $this->handleJsonMode($request),

        };

        $this->validateResponse($response);

        $data = $response->json();

        /** @var array<array-key, mixed> $output */
        $output = data_get($data, 'output', []);

        $finishReason = $this->mapFinishReason($data);

        $request->addMessage(new AssistantMessage(
            content: OutputItems::text($output),
            toolCalls: $finishReason === FinishReason::ToolCalls
                ? ToolCallMap::map($this->extractFunctionCalls($data), $this->extractReasoningOutput($data))
                : [],
            additionalContent: OutputItems::replayContent($output, $finishReason),
        ));

        if ($this->exceededMaxOutputTokens($data, $finishReason)) {
            throw new PrismException(sprintf(
                'OpenAI: max tokens exceeded (status: %s, type: %s). If using a reasoning model, increase max_tokens to account for internal reasoning token usage.',
                data_get($data, 'output.{last}.status', 'n/a'),
                data_get($data, 'output.{last}.type', 'n/a'),
            ));
        }

        return $finishReason === FinishReason::ToolCalls
            ? $this->handleToolCalls($data, $request, $response)
            : $this->handleFinalStop($data, $request, $response);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request, ClientResponse $clientResponse): StructuredResponse
    {
        $toolResults = $this->callTools(
            $request->tools(),
            ToolCallMap::map($this->extractFunctionCalls($data)),
        );

        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        $this->addStep($data, $request, $clientResponse, $toolResults);

        if ($this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleFinalStop(array $data, Request $request, ClientResponse $clientResponse): StructuredResponse
    {
        $this->addStep($data, $request, $clientResponse);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(array $data, Request $request, ClientResponse $clientResponse, array $toolResults = []): void
    {
        $finishReason = $this->mapFinishReason($data);
        $isStructuredStep = $finishReason !== FinishReason::ToolCalls;

        /** @var array<array-key, mixed> $output */
        $output = data_get($data, 'output', []);

        $toolCalls = $finishReason === FinishReason::ToolCalls
            ? ToolCallMap::map(
                $this->extractFunctionCalls($data),
                $this->extractReasoningOutput($data),
            )
            : [];

        $text = $isStructuredStep ? OutputItems::finalMessageText($output) : OutputItems::text($output);

        $this->responseBuilder->addStep(new Step(
            text: $text,
            finishReason: $finishReason,
            usage: $this->mapUsage($data),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
                rateLimits: $this->processRateLimits($clientResponse),
                serviceTier: data_get($data, 'service_tier'),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [
                ...Arr::whereNotNull([
                    'citations' => $this->extractCitations($data),
                ]),
                ...OutputItems::replayContent($output, $finishReason),
            ],
            structured: $isStructuredStep && $finishReason !== FinishReason::Refusal ? $this->extractStructuredData($text) : [],
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            raw: $data,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    protected function extractFunctionCalls(array $data): array
    {
        return array_filter(
            data_get($data, 'output', []),
            fn (array $output): bool => ($output['type'] ?? null) === 'function_call'
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    protected function extractReasoningOutput(array $data): array
    {
        return array_filter(
            data_get($data, 'output', []),
            fn (array $output): bool => ($output['type'] ?? null) === 'reasoning'
        );
    }

    /**
     * @param  array{type: 'json_schema', name: string, schema: array<mixed>, strict?: bool}|array{type: 'json_object'}  $responseFormat
     */
    protected function sendRequest(Request $request, array $responseFormat): ClientResponse
    {
        /** @var ClientResponse $response */
        $response = $this->client->post(
            'responses',
            array_merge([
                'model' => $request->model(),
                'input' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
            ], Arr::whereNotNull([
                'max_output_tokens' => $request->maxTokens(),
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'metadata' => $request->providerOptions('metadata'),
                'tools' => $this->buildTools($request),
                'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                'parallel_tool_calls' => $request->providerOptions('parallel_tool_calls') ?? false,
                'previous_response_id' => $request->providerOptions('previous_response_id'),
                'service_tier' => $request->providerOptions('service_tier'),
                'truncation' => $request->providerOptions('truncation'),
                'reasoning' => $request->providerOptions('reasoning'),
                'store' => $request->providerOptions('store'),
                'text' => [
                    'format' => $responseFormat,
                ],
            ]))
        );

        return $response;
    }

    protected function handleAutoMode(Request $request): ClientResponse
    {
        $mode = StructuredModeResolver::forModel($request->model());

        return match ($mode) {
            StructuredMode::Structured => $this->handleStructuredMode($request),
            StructuredMode::Json => $this->handleJsonMode($request),
            default => throw new PrismException('Could not determine structured mode for your request'),
        };
    }

    protected function handleStructuredMode(Request $request): ClientResponse
    {
        /** @var array{type: 'json_schema', name: string, schema: array<mixed>, strict?: bool} $responseFormat */
        $responseFormat = Arr::whereNotNull([
            'type' => 'json_schema',
            'name' => $request->schema()->name(),
            'schema' => $request->schema()->toArray(),
            'strict' => is_null($request->providerOptions('schema.strict'))
                ? null
                : $request->providerOptions('schema.strict'),
        ]);

        return $this->sendRequest($request, $responseFormat);
    }

    protected function handleJsonMode(Request $request): ClientResponse
    {
        $request = $this->appendMessageForJsonMode($request);

        return $this->sendRequest($request, [
            'type' => 'json_object',
        ]);
    }

    protected function appendMessageForJsonMode(Request $request): Request
    {
        return $request->addMessage(new SystemMessage(sprintf(
            "Respond with JSON that matches the following schema: \n %s",
            json_encode($request->schema()->toArray(), JSON_PRETTY_PRINT)
        )));
    }

    /**
     * @return array<int|string,mixed>
     */
    protected function buildTools(Request $request): array
    {
        $tools = ToolMap::map($request->tools());

        if ($request->providerTools() === []) {
            return $tools;
        }

        $providerTools = array_map(
            fn (ProviderTool $tool): array => [
                'type' => $tool->type,
                ...$tool->options,
            ],
            $request->providerTools()
        );

        return array_merge($providerTools, $tools);
    }
}
