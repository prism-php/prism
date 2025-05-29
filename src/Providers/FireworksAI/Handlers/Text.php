<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\FireworksAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\FireworksAI\Concerns\ValidateResponse;
use Prism\Prism\Providers\FireworksAI\Maps\MessageMap;
use Prism\Prism\Providers\FireworksAI\Maps\ToolCallMap;
use Prism\Prism\Providers\FireworksAI\Maps\ToolChoiceMap;
use Prism\Prism\Providers\OpenAI\Maps\FinishReasonMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class Text
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

        $content = data_get($data, 'choices.0.message.content') ?? '';
        $extracted = $this->extractReasoning($content);

        $responseMessage = new AssistantMessage(
            $extracted['output'],
            ToolCallMap::map(data_get($data, 'choices.0.message.tool_calls', [])),
        );

        $this->responseBuilder->addResponseMessage($responseMessage);

        $request->addMessage($responseMessage);

        $finishReason = FinishReasonMap::map(data_get($data, 'choices.0.finish_reason', ''));

        return match ($finishReason) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request, $response, $extracted),
            FinishReason::Stop => $this->handleStop($data, $request, $response, $extracted),
            FinishReason::Length => $this->handleStop($data, $request, $response, $extracted),
            default => throw new PrismException('FireworksAI: unknown finish reason: '.$finishReason->value),
        };
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
                'tools' => ToolMap::map($request->tools()),
                'tool_choice' => $this->mapToolChoice($request),
                'context_length_exceeded_behavior' => $request->providerOptions('context_length_exceeded_behavior'),
                'repetition_penalty' => $request->providerOptions('repetition_penalty'),
                'mirostat_lr' => $request->providerOptions('mirostat_lr'),
                'mirostat_target' => $request->providerOptions('mirostat_target'),
                'raw_output' => $request->providerOptions('raw_output'),
                'echo' => $request->providerOptions('echo'),
            ]));

            if ($grammar = $request->providerOptions('grammar')) {
                $body['response_format'] = [
                    'type' => 'grammar',
                    'grammar' => $grammar,
                ];
            }

            return $this->client->post('chat/completions', $body);
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }
    }

    /**
     * @return array<string, mixed>|string|null
     */
    protected function mapToolChoice(Request $request): array|string|null
    {
        $toolChoice = $request->toolChoice();

        if ($toolChoice === null) {
            return null;
        }

        if ($request->providerOptions('tool_choice') === 'any') {
            return 'any';
        }

        return ToolChoiceMap::map($toolChoice);
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
     * @param  array{reasoning: ?string, output: string}  $extracted
     */
    protected function handleToolCalls(array $data, Request $request, ClientResponse $clientResponse, array $extracted): Response
    {
        $toolResults = $this->callTools(
            $request->tools(),
            ToolCallMap::map(data_get($data, 'choices.0.message.tool_calls', [])),
        );

        $request->addMessage(new ToolResultMessage($toolResults));

        $this->addStep($data, $request, $clientResponse, FinishReason::ToolCalls, $toolResults, ['reasoning' => null, 'output' => data_get($data, 'choices.0.message.content') ?? '']);

        if ($this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{reasoning: ?string, output: string}  $extracted
     */
    protected function handleStop(array $data, Request $request, ClientResponse $clientResponse, array $extracted): Response
    {
        $this->addStep($data, $request, $clientResponse, FinishReason::Stop, [], $extracted);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ToolResult[]  $toolResults
     * @param  array{reasoning: ?string, output: string}  $extracted
     */
    protected function addStep(
        array $data,
        Request $request,
        ClientResponse $clientResponse,
        FinishReason $finishReason,
        array $toolResults = [],
        array $extracted = ['reasoning' => null, 'output' => '']
    ): void {
        $additionalContent = [];
        if ($extracted['reasoning'] !== null) {
            $additionalContent['reasoning'] = $extracted['reasoning'];
        }

        $this->responseBuilder->addStep(new Step(
            text: $extracted['output'],
            finishReason: $finishReason,
            toolCalls: ToolCallMap::map(data_get($data, 'choices.0.message.tool_calls', [])),
            toolResults: $toolResults,
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
            additionalContent: $additionalContent,
            systemPrompts: $request->systemPrompts(),
        ));
    }
}
