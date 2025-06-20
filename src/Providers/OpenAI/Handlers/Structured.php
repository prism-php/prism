<?php

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenAI\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessesRateLimits;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Support\StructuredModeResolver;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use MapsFinishReason;
    use ProcessesRateLimits;
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

        $this->handleRefusal(data_get($data, 'output.{last}.content.0', []));

        $responseMessage = new AssistantMessage(
            data_get($data, 'output.{last}.content.0.text') ?? '',
        );

        $this->responseBuilder->addResponseMessage($responseMessage);

        $request->addMessage($responseMessage);

        $this->addStep($data, $request, $response);

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function addStep(array $data, Request $request, ClientResponse $clientResponse): void
    {
        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'output.{last}.content.0.text') ?? '',
            finishReason: $this->mapFinishReason($data),
            usage: new Usage(
                promptTokens: data_get($data, 'usage.input_tokens', 0) - data_get($data, 'usage.input_tokens_details.cached_tokens', 0),
                completionTokens: data_get($data, 'usage.output_tokens'),
                cacheReadInputTokens: data_get($data, 'usage.input_tokens_details.cached_tokens'),
                thoughtTokens: data_get($data, 'usage.output_token_details.reasoning_tokens'),
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
                rateLimits: $this->processRateLimits($clientResponse)
            ),
            messages: $request->messages(),
            additionalContent: [],
            systemPrompts: $request->systemPrompts(),
        ));
    }

    /**
     * @param  array{type: 'json_schema', name: string, schema: array<mixed>, strict?: bool}|array{type: 'json_object'}  $responseFormat
     */
    protected function sendRequest(Request $request, array $responseFormat): ClientResponse
    {
        return $this->client->post(
            'responses',
            array_merge([
                'model' => $request->model(),
                'input' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_output_tokens' => $request->maxTokens(),
            ], Arr::whereNotNull([
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'metadata' => $request->providerOptions('metadata'),
                'previous_response_id' => $request->providerOptions('previous_response_id'),
                'truncation' => $request->providerOptions('truncation'),
                'text' => [
                    'format' => $responseFormat,
                ],
            ]))
        );
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
        $mode = StructuredModeResolver::forModel($request->model());

        if ($mode !== StructuredMode::Structured) {
            throw new PrismException(sprintf('%s model does not support structured mode', $request->model()));
        }

        /** @var array{type: 'json_schema', name: string, schema: array<mixed>, strict?: bool} $responseFormat */
        $responseFormat = Arr::whereNotNull([
            'type' => 'json_schema',
            'name' => $request->schema()->name(),
            'schema' => $request->schema()->toArray(),
            'strict' => $request->providerOptions('schema.strict') ? true : null,
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

    /**
     * @param  array<string, string>  $message
     */
    protected function handleRefusal(array $message): void
    {
        if (data_get($message, 'type') === 'refusal') {
            throw new PrismException(sprintf('OpenAI Refusal: %s', $message['refusal'] ?? 'Reason unknown.'));
        }
    }

    protected function appendMessageForJsonMode(Request $request): Request
    {
        return $request->addMessage(new SystemMessage(sprintf(
            "Respond with JSON that matches the following schema: \n %s",
            json_encode($request->schema()->toArray(), JSON_PRETTY_PRINT)
        )));
    }
}
