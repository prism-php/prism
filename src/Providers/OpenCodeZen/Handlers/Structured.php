<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenCodeZen\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenCodeZen\Concerns\BuildsRequestOptions;
use Prism\Prism\Providers\OpenCodeZen\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenCodeZen\Concerns\ValidatesResponses;
use Prism\Prism\Providers\OpenCodeZen\Maps\FinishReasonMap;
use Prism\Prism\Providers\OpenCodeZen\Maps\MessageMap;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Structured
{
    use BuildsRequestOptions;
    use MapsFinishReason;
    use ValidatesResponses;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): StructuredResponse
    {
        $data = $this->sendRequest($request);

        $this->validateResponse($data);

        return $this->createResponse($request, $data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendRequest(Request $request): array
    {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = $this->client->post(
            'chat/completions',
            array_merge([
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_tokens' => $request->maxTokens(),
                'structured_outputs' => true,
            ], $this->buildRequestOptions($request, [
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $request->schema()->name(),
                        'strict' => true,
                        'schema' => $request->schema()->toArray(),
                    ],
                ],
            ]))
        );

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateResponse(array $data): void
    {
        if ($data === []) {
            throw PrismException::providerResponseError('OpenCodeZen Error: Empty response');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createResponse(Request $request, array $data): StructuredResponse
    {
        $text = Arr::get($data, 'choices.0.message.content') ?? '';

        $responseMessage = new AssistantMessage($text);
        $request->addMessage($responseMessage);

        $step = new Step(
            text: $text,
            finishReason: FinishReasonMap::map(Arr::get($data, 'choices.0.finish_reason', '')),
            usage: new Usage(
                Arr::get($data, 'usage.prompt_tokens'),
                Arr::get($data, 'usage.completion_tokens'),
            ),
            meta: new Meta(
                id: Arr::get($data, 'id'),
                model: Arr::get($data, 'model'),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [],
        );

        $this->responseBuilder->addStep($step);

        return $this->responseBuilder->toResponse();
    }
}
