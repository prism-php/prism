<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenAI\Concerns\BuildsTools;
use Prism\Prism\Providers\OpenAI\Concerns\ExtractsCitations;
use Prism\Prism\Providers\OpenAI\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ProviderToolCallMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolCallMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class Text
{
    use BuildsTools;
    use CallsTools;
    use ExtractsCitations;
    use MapsFinishReason;
    use ProcessRateLimits;
    use ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    /** @var ?MessagePartWithCitations[] */
    protected ?array $citations = null;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): Response
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        $this->citations = $this->extractCitations($data);

        return match ($this->mapFinishReason($data)) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request, $response),
            FinishReason::Stop => $this->handleStop($data, $request, $response),
            FinishReason::Length => throw new PrismException('OpenAI: max tokens exceeded'),
            default => throw new PrismException('OpenAI: unknown finish reason'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request, ClientResponse $clientResponse): Response
    {
        $toolCalls = ToolCallMap::map(
            array_filter(data_get($data, 'output', []), fn (array $output): bool => $output['type'] === 'function_call'),
            array_filter(data_get($data, 'output', []), fn (array $output): bool => $output['type'] === 'reasoning'),
        );

        $hasPendingToolCalls = false;
        $toolResults = $this->callTools(
            $request->tools(),
            $toolCalls,
            $hasPendingToolCalls,
        );

        $this->addStep($data, $request, $clientResponse, $toolResults);

        $providerToolCalls = ProviderToolCallMap::map(data_get($data, 'output', []));

        $request->addMessage(new AssistantMessage(
            content: data_get($data, 'output.{last}.content.0.text') ?? '',
            toolCalls: $toolCalls,
            additionalContent: Arr::whereNotNull([
                'citations' => $this->citations,
                'provider_tool_calls' => $providerToolCalls === [] ? null : $providerToolCalls,
            ]),
        ));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        if (! $hasPendingToolCalls && $this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleStop(array $data, Request $request, ClientResponse $clientResponse): Response
    {
        $this->addStep($data, $request, $clientResponse);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    protected function sendRequest(Request $request): ClientResponse
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
                'parallel_tool_calls' => $request->providerOptions('parallel_tool_calls'),
                'previous_response_id' => $request->providerOptions('previous_response_id'),
                'service_tier' => $request->providerOptions('service_tier'),
                'text' => $request->providerOptions('text_verbosity') ? [
                    'verbosity' => $request->providerOptions('text_verbosity'),
                ] : null,
                'truncation' => $request->providerOptions('truncation'),
                'reasoning' => $request->providerOptions('reasoning'),
                'store' => $request->providerOptions('store'),
            ]))
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(
        array $data,
        Request $request,
        ClientResponse $clientResponse,
        array $toolResults = []
    ): void {
        /** @var array<array-key, array<string, mixed>> $output */
        $output = data_get($data, 'output', []);

        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'output.{last}.content.0.text') ?? '',
            finishReason: $this->mapFinishReason($data),
            toolCalls: ToolCallMap::map(array_filter($output, fn (array $output): bool => $output['type'] === 'function_call')),
            toolResults: $toolResults,
            providerToolCalls: ProviderToolCallMap::map($output),
            usage: new Usage(
                promptTokens: data_get($data, 'usage.input_tokens', 0) - data_get($data, 'usage.input_tokens_details.cached_tokens', 0),
                completionTokens: data_get($data, 'usage.output_tokens'),
                cacheReadInputTokens: data_get($data, 'usage.input_tokens_details.cached_tokens'),
                thoughtTokens: data_get($data, 'usage.output_tokens_details.reasoning_tokens'),
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
                rateLimits: $this->processRateLimits($clientResponse),
                serviceTier: data_get($data, 'service_tier'),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: Arr::whereNotNull([
                'citations' => $this->citations,
                'searchQueries' => collect($output)
                    ->filter(fn (array $item): bool => ($item['type'] ?? null) === 'web_search_call')
                    ->filter(fn (array $item): bool => data_get($item, 'action.type') === 'search')
                    ->map(fn (array $item): ?string => data_get($item, 'action.query'))
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray() ?: null,
                'openPageUrls' => collect($output)
                    ->filter(fn (array $item): bool => ($item['type'] ?? null) === 'web_search_call')
                    ->filter(fn (array $item): bool => data_get($item, 'action.type') === 'open_page')
                    ->map(fn (array $item): ?string => data_get($item, 'action.url'))
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray() ?: null,
                'findInPagePatterns' => collect($output)
                    ->filter(fn (array $item): bool => ($item['type'] ?? null) === 'web_search_call')
                    ->filter(fn (array $item): bool => data_get($item, 'action.type') === 'find_in_page')
                    ->map(fn (array $item): ?string => data_get($item, 'action.pattern'))
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray() ?: null,
                'reasoningSummaries' => collect($output)
                    ->filter(fn (array $output): bool => $output['type'] === 'reasoning')
                    ->flatMap(fn (array $output): array => Arr::pluck($output['summary'] ?? [], 'text'))
                    ->filter()
                    ->toArray(),
            ]),
            raw: $data,
        ));
    }
}
