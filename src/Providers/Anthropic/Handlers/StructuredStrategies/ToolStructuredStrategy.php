<?php

namespace Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies;

use Illuminate\Http\Client\Response as HttpResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Structured\Response as PrismResponse;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class ToolStructuredStrategy extends AnthropicStructuredStrategy
{
    public function appendMessages(): void
    {
        if ($this->request->providerOptions('thinking.enabled') === false) {
            return;
        }

        $this->request->addMessage(new UserMessage(sprintf(
            "Please use the output_structured_data tool to provide your response. If for any reason you cannot use the tool, respond with ONLY JSON (i.e. not in backticks or a code block, with NO CONTENT outside the JSON) that matches the following schema: \n %s",
            json_encode($this->request->schema()->toArray(), JSON_PRETTY_PRINT)
        )));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function mutatePayload(array $payload): array
    {
        $schemaArray = $this->request->schema()->toArray();

        $payload = [
            ...$payload,
            'tools' => [
                [
                    'name' => 'output_structured_data',
                    'description' => 'Output data in the requested structure',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => $schemaArray['properties'],
                        'required' => $schemaArray['required'] ?? [],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];

        if ($this->request->providerOptions('thinking.enabled') !== true) {
            $payload['tool_choice'] = ['type' => 'tool', 'name' => 'output_structured_data'];
        }

        return $payload;
    }

    public function mutateResponse(HttpResponse $httpResponse, PrismResponse $prismResponse): PrismResponse
    {
        $structured = [];
        $additionalContent = $prismResponse->additionalContent;

        $data = $httpResponse->json();

        $toolCalls = array_values(array_filter(
            data_get($data, 'content', []),
            fn ($content): bool => data_get($content, 'type') === 'tool_use' && data_get($content, 'name') === 'output_structured_data'
        ));

        $structured = data_get($toolCalls, '0.input', []);

        return new PrismResponse(
            steps: $prismResponse->steps,
            responseMessages: $prismResponse->responseMessages,
            text: $prismResponse->text,
            structured: $structured,
            finishReason: $prismResponse->finishReason,
            usage: $prismResponse->usage,
            meta: $prismResponse->meta,
            additionalContent: $additionalContent
        );
    }

    protected function checkStrategySupport(): void
    {
        if ($this->request->providerOptions('citations') === true) {
            throw new PrismException(
                'Citations are not supported with tool calling mode. Please set use_tool_calling to false in provider options to use citations.'
            );
        }
    }
}
