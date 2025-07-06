<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\Providers\OpenAI\Concerns\BuildsTools;
use Prism\Prism\Providers\OpenAI\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolCallMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use Prism\Prism\Providers\TextHandler;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Text extends TextHandler
{
    use BuildsTools;
    use CallsTools;
    use MapsFinishReason;
    use ValidatesResponse;

    /**
     * @param  TextRequest  $request
     */
    #[\Override]
    public static function buildHttpRequestPayload(PrismRequest $request): array
    {
        if (! $request->is(TextRequest::class)) {
            throw new \InvalidArgumentException('Request must be an instance of '.TextRequest::class);
        }

        return array_merge([
            'model' => $request->model(),
            'input' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
            'max_output_tokens' => $request->maxTokens(),
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'metadata' => $request->providerOptions('metadata'),
            'tools' => static::buildTools($request),
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            'previous_response_id' => $request->providerOptions('previous_response_id'),
            'truncation' => $request->providerOptions('truncation'),
        ]));
    }

    protected function handleToolCalls(): TextResponse
    {
        $data = $this->httpResponse->json();

        $toolResults = $this->callTools(
            $this->request->tools(),
            ToolCallMap::map(array_filter(data_get($data, 'output', []), fn (array $output): bool => $output['type'] === 'function_call')),
        );

        $this->request->addMessage(new ToolResultMessage($toolResults));

        $this->addStep($toolResults);

        if ($this->shouldContinue()) {
            return $this->handle();
        }

        return $this->responseBuilder->toResponse();
    }

    protected function sendRequest(): void
    {
        $this->httpResponse = $this->client->post(
            'responses',
            static::buildHttpRequestPayload($this->request)
        );
    }

    protected function prepareTempResponse(): void
    {
        $data = $this->httpResponse->json();

        $this->tempResponse = new TextResponse(
            steps: new Collection,
            responseMessages: new Collection,
            messages: new Collection,
            text: data_get($data, 'output.{last}.content.0.text') ?? '',
            finishReason: $this->mapFinishReason($data),
            toolCalls: ToolCallMap::map(
                array_filter(data_get($data, 'output', []), fn (array $output): bool => $output['type'] === 'function_call'),
                array_filter(data_get($data, 'output', []), fn (array $output): bool => $output['type'] === 'reasoning'),
            ),
            toolResults: [],
            usage: new Usage(
                promptTokens: data_get($data, 'usage.input_tokens', 0) - data_get($data, 'usage.input_tokens_details.cached_tokens', 0),
                completionTokens: data_get($data, 'usage.output_tokens'),
                cacheReadInputTokens: data_get($data, 'usage.input_tokens_details.cached_tokens'),
                thoughtTokens: data_get($data, 'usage.output_token_details.reasoning_tokens'),
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
            ),
            additionalContent: [],
        );
    }
}
