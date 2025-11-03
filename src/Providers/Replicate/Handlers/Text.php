<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Replicate\Concerns\HandlesPredictions;
use Prism\Prism\Providers\Replicate\Maps\FinishReasonMap;
use Prism\Prism\Providers\Replicate\Maps\MessageMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Text
{
    use HandlesPredictions;

    protected ResponseBuilder $responseBuilder;

    public function __construct(
        protected PendingRequest $client,
        protected int $pollingInterval = 1000,
        protected int $maxWaitTime = 60,
    ) {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): Response
    {
        // Build the prompt from messages
        $prompt = MessageMap::map($request->messages());

        // Prepare the prediction payload
        $payload = [
            'version' => $this->extractVersionFromModel($request->model()),
            'input' => array_merge(
                ['prompt' => $prompt],
                $this->buildInputParameters($request)
            ),
        ];

        // Create prediction
        $prediction = $this->createPrediction($this->client, $payload);

        // Wait for completion
        $prediction = $this->waitForPrediction(
            $this->client,
            $prediction->id,
            $this->pollingInterval,
            $this->maxWaitTime
        );

        // Check for errors
        if ($prediction->isFailed()) {
            throw new PrismException(
                "Replicate prediction failed: {$prediction->error}"
            );
        }

        // Extract the text output
        $text = $this->extractTextFromOutput($prediction->output);

        // Create assistant message
        $responseMessage = new AssistantMessage(
            content: $text,
        );

        $request->addMessage($responseMessage);

        // Add step to response builder
        $this->responseBuilder->addStep(new Step(
            text: $text,
            finishReason: FinishReasonMap::map($prediction->status),
            toolCalls: [],
            toolResults: [],
            usage: new Usage(
                promptTokens: 0, // Replicate doesn't provide token counts
                completionTokens: 0,
            ),
            meta: new Meta(
                id: $prediction->id,
                model: $request->model(),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [
                'metrics' => $prediction->metrics,
            ],
        ));

        return $this->responseBuilder->toResponse();
    }

    /**
     * Extract version ID from model string.
     * Supports formats like "owner/model:version" or just "owner/model".
     */
    protected function extractVersionFromModel(string $model): string
    {
        // Otherwise, return as-is and let Replicate use the latest version
        return $model;
    }

    /**
     * Build input parameters from request.
     *
     * @return array<string, mixed>
     */
    protected function buildInputParameters(Request $request): array
    {
        $params = [];

        if ($request->maxTokens() !== null) {
            $params['max_tokens'] = $request->maxTokens();
            $params['max_length'] = $request->maxTokens(); // Some models use max_length
        }

        if ($request->temperature() !== null) {
            $params['temperature'] = $request->temperature();
        }

        if ($request->topP() !== null) {
            $params['top_p'] = $request->topP();
        }

        // Add any provider-specific options
        $providerOptions = $request->providerOptions();
        if (! empty($providerOptions)) {
            return array_merge($params, $providerOptions);
        }

        return $params;
    }

    /**
     * Extract text from Replicate output.
     * Replicate outputs can be strings, arrays, or objects.
     */
    protected function extractTextFromOutput(mixed $output): string
    {
        if (is_string($output)) {
            return $output;
        }

        if (is_array($output)) {
            // If it's an array of strings, join them
            if (isset($output[0]) && is_string($output[0])) {
                return implode('', $output);
            }

            // If it has a 'text' or 'output' key
            if (isset($output['text'])) {
                return (string) $output['text'];
            }

            if (isset($output['output'])) {
                return $this->extractTextFromOutput($output['output']);
            }
        }

        return '';
    }
}
