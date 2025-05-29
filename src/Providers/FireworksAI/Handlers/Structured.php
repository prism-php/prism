<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\FireworksAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\FireworksAI\Concerns\ValidateResponse;
use Prism\Prism\Providers\FireworksAI\Maps\MessageMap;
use Prism\Prism\Providers\FireworksAI\Maps\ToolCallMap;
use Prism\Prism\Providers\OpenAI\Maps\FinishReasonMap;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class Structured
{
    use CallsTools, ValidateResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): Response
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        $this->handleResponse($data, $request, $response);

        return $this->responseBuilder->toResponse();
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        try {
            $body = array_merge([
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_tokens' => $request->maxTokens(),
            ], Arr::whereNotNull([
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'metadata' => $request->providerOptions('metadata'),
                'context_length_exceeded_behavior' => $request->providerOptions('context_length_exceeded_behavior'),
                'repetition_penalty' => $request->providerOptions('repetition_penalty'),
                'mirostat_lr' => $request->providerOptions('mirostat_lr'),
                'mirostat_target' => $request->providerOptions('mirostat_target'),
                'raw_output' => $request->providerOptions('raw_output'),
                'echo' => $request->providerOptions('echo'),
            ]));

            if ($request->mode() === StructuredMode::Json) {
                $responseFormat = ['type' => 'json_object'];

                if ($request->schema() instanceof \Prism\Prism\Contracts\Schema) {
                    $responseFormat['schema'] = $request->schema()->toArray();
                }

                $body['response_format'] = $responseFormat;
            } elseif ($grammar = $request->providerOptions('grammar')) {
                $body['response_format'] = [
                    'type' => 'grammar',
                    'grammar' => $grammar,
                ];
            } else {
                // Default to tool mode for structured output when not using JSON mode or grammar
                $tool = [
                    'type' => 'function',
                    'function' => [
                        'name' => 'extract',
                        'description' => 'Extract structured data',
                        'parameters' => $request->schema()->toArray(),
                    ],
                ];

                $body['tools'] = [$tool];
                $body['tool_choice'] = $request->providerOptions('tool_choice') === 'any'
                    ? 'any'
                    : ['type' => 'function', 'function' => ['name' => 'extract']];
            }

            return $this->client->post('chat/completions', $body);
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleResponse(array $data, Request $request, ClientResponse $clientResponse): void
    {
        if (isset($data['choices'][0]['message']['tool_calls'])) {
            $this->handleToolResponse($data, $request, $clientResponse);
        } else {
            $this->handleJsonResponse($data, $request, $clientResponse);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolResponse(array $data, Request $request, ClientResponse $clientResponse): void
    {
        $toolCalls = ToolCallMap::map(data_get($data, 'choices.0.message.tool_calls', []));

        if ($toolCalls !== []) {
            $toolCall = $toolCalls[0];
            $content = $toolCall->arguments();

            $extracted = $this->extractReasoningFromData($content);

            $additionalContent = [];
            if ($extracted['reasoning'] !== null) {
                $additionalContent['reasoning'] = $extracted['reasoning'];
            }

            $this->responseBuilder->addStep(new Step(
                text: json_encode($extracted['data']) ?: '',
                finishReason: FinishReasonMap::map(data_get($data, 'choices.0.finish_reason', '')),
                usage: new Usage(
                    promptTokens: data_get($data, 'usage.prompt_tokens', 0),
                    completionTokens: data_get($data, 'usage.completion_tokens'),
                ),
                meta: new Meta(
                    id: data_get($data, 'id'),
                    model: data_get($data, 'model'),
                    rateLimits: $this->processRateLimits($clientResponse)
                ),
                messages: $request->messages(),
                systemPrompts: $request->systemPrompts(),
                additionalContent: $additionalContent,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleJsonResponse(array $data, Request $request, ClientResponse $clientResponse): void
    {
        $content = data_get($data, 'choices.0.message.content') ?? '';

        $extracted = $this->extractReasoning($content);

        $responseMessage = new AssistantMessage($extracted['output'], []);

        $request->addMessage($responseMessage);

        $additionalContent = [];
        if ($extracted['reasoning'] !== null) {
            $additionalContent['reasoning'] = $extracted['reasoning'];
        }

        $this->responseBuilder->addStep(new Step(
            text: $extracted['output'],
            finishReason: FinishReasonMap::map(data_get($data, 'choices.0.finish_reason', '')),
            usage: new Usage(
                promptTokens: data_get($data, 'usage.prompt_tokens', 0),
                completionTokens: data_get($data, 'usage.completion_tokens'),
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
                rateLimits: $this->processRateLimits($clientResponse)
            ),
            messages: $request->messages(),
            systemPrompts: [],
            additionalContent: $additionalContent,
        ));
    }

    /**
     * @return array{reasoning: ?string, output: string}
     */
    protected function extractReasoning(string $content): array
    {
        if (preg_match('/<think>(.*?)<\/think>/s', $content, $matches)) {
            $reasoning = trim($matches[1]);
            $output = trim((string) preg_replace('/<think>.*?<\/think>/s', '', $content));

            return [
                'reasoning' => $reasoning,
                'output' => $output,
            ];
        }

        return [
            'reasoning' => null,
            'output' => $content,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{reasoning: ?string, data: array<string, mixed>}
     */
    protected function extractReasoningFromData(array $data): array
    {
        if (isset($data['reasoning'])) {
            $reasoning = $data['reasoning'];
            unset($data['reasoning']);

            return [
                'reasoning' => $reasoning,
                'data' => $data,
            ];
        }

        return [
            'reasoning' => null,
            'data' => $data,
        ];
    }
}
