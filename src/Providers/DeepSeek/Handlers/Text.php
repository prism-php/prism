<?php

declare(strict_types=1);

namespace EchoLabs\Prism\Providers\DeepSeek\Handlers;

use EchoLabs\Prism\Exceptions\PrismException;
use EchoLabs\Prism\Providers\DeepSeek\Maps\FinishReasonMap;
use EchoLabs\Prism\Providers\DeepSeek\Maps\MessageMap;
use EchoLabs\Prism\Providers\DeepSeek\Maps\ToolCallMap;
use EchoLabs\Prism\Providers\DeepSeek\Maps\ToolChoiceMap;
use EchoLabs\Prism\Providers\DeepSeek\Maps\ToolMap;
use EchoLabs\Prism\Text\Request;
use EchoLabs\Prism\Text\Response as TextResponse;
use EchoLabs\Prism\Text\ResponseBuilder;
use EchoLabs\Prism\Text\Step;
use EchoLabs\Prism\ValueObjects\ResponseMeta;
use EchoLabs\Prism\ValueObjects\Usage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;
use EchoLabs\Prism\Concerns\CallsTools;
use EchoLabs\Prism\Enums\FinishReason;
use EchoLabs\Prism\ValueObjects\Messages\AssistantMessage;
use EchoLabs\Prism\ValueObjects\Messages\ToolResultMessage;

class Text
{
    use CallsTools;

    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): TextResponse
    {
        $responseBuilder = new ResponseBuilder;
        $currentRequest = $request;
        $stepCount = 0;

        do {
            $stepCount++;
            $response = $this->sendRequest($currentRequest);
            $data = $response->json();

            if (! $data) {
                throw PrismException::providerResponseError(vsprintf(
                    'DeepSeek Error: %s',
                    [
                        (string) $response->getBody(),
                    ]
                ));
            }

            $text = data_get($data, 'choices.0.message.content') ?? '';
            $finishReason = FinishReasonMap::map(data_get($data, 'choices.0.finish_reason', ''));
            $toolCalls = ToolCallMap::map(data_get($data, 'choices.0.message.tool_calls', []));
            $toolResults = [];

            $responseMessage = new AssistantMessage($text, $toolCalls, []);
            $responseBuilder->addResponseMessage($responseMessage);
            $currentRequest = $currentRequest->addMessage($responseMessage);

            if ($finishReason === FinishReason::ToolCalls) {
                $toolResults = $this->callTools($currentRequest->tools, $toolCalls);
                $toolResultMessage = new ToolResultMessage($toolResults);
                $currentRequest = $currentRequest->addMessage($toolResultMessage);
            }

            $step = new Step(
                text: $text,
                finishReason: $finishReason,
                toolCalls: $toolCalls,
                toolResults: $toolResults,
                usage: new Usage(
                    data_get($data, 'usage.prompt_tokens'),
                    data_get($data, 'usage.completion_tokens'),
                ),
                responseMeta: new ResponseMeta(
                    id: data_get($data, 'id'),
                    model: data_get($data, 'model'),
                ),
                messages: $currentRequest->messages,
                additionalContent: [],
            );

            $responseBuilder->addStep($step);

        } while ($stepCount < $request->maxSteps && $finishReason === FinishReason::ToolCalls);

        return $responseBuilder->toResponse();
    }

    public function sendRequest(Request $request): Response
    {
        return $this->client->post(
            'chat/completions',
            array_merge([
                'model' => $request->model,
                'messages' => (new MessageMap($request->messages, $request->systemPrompt ?? ''))(),
                'max_completion_tokens' => $request->maxTokens,
            ], array_filter([
                'temperature' => $request->temperature,
                'top_p' => $request->topP,
                'tools' => ToolMap::map($request->tools),
                'tool_choice' => ToolChoiceMap::map($request->toolChoice),
            ]))
        );
    }
}
