<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Replicate\Concerns\HandlesPredictions;
use Prism\Prism\Providers\Replicate\Maps\FinishReasonMap;
use Prism\Prism\Providers\Replicate\Maps\MessageMap;
use Prism\Prism\Providers\Replicate\Maps\ToolCallMap;
use Prism\Prism\Providers\Replicate\Maps\ToolMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Text
{
    use CallsTools, HandlesPredictions;

    protected ResponseBuilder $responseBuilder;

    public function __construct(
        protected PendingRequest $client,
        protected bool $useSyncMode = true,
        protected int $pollingInterval = 1000,
        protected int $maxWaitTime = 60,
    ) {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): Response
    {
        // Build payload with tool support
        $payload = $this->buildPayload($request);

        // Create prediction and wait for completion (uses sync mode if enabled)
        $prediction = $this->createAndWaitForPrediction(
            $this->client,
            $payload,
            $this->useSyncMode,
            $this->pollingInterval,
            $this->maxWaitTime
        );

        // Check for errors
        if ($prediction->isFailed()) {
            throw new PrismException(
                "Replicate prediction failed: {$prediction->error}"
            );
        }

        // Check if output contains tool calls
        if ($request->tools() !== [] && ToolCallMap::hasToolCalls($prediction->output)) {
            return $this->handleToolCalls($prediction, $request);
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
     * Handle tool calls from model output.
     */
    protected function handleToolCalls(object $prediction, Request $request): Response
    {
        // Parse tool calls from output
        /** @phpstan-ignore property.notFound */
        $toolCalls = ToolCallMap::map($prediction->output);

        // Execute tools
        $toolResults = $this->callTools($request->tools(), $toolCalls);

        // Extract text content (may be empty for tool-only responses)
        /** @phpstan-ignore property.notFound */
        $text = $this->extractTextFromOutput($prediction->output);

        // Create assistant message with tool calls
        $responseMessage = new AssistantMessage(
            content: $text,
            toolCalls: $toolCalls,
        );

        $request->addMessage($responseMessage);

        // Add tool result message
        $request->addMessage(new ToolResultMessage($toolResults));

        // Add step to response builder
        $this->responseBuilder->addStep(new Step(
            text: $text,
            finishReason: FinishReason::ToolCalls,
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            usage: new Usage(
                promptTokens: 0,
                completionTokens: 0,
            ),
            meta: new Meta(
                /** @phpstan-ignore property.notFound */
                id: $prediction->id,
                model: $request->model(),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: [
                /** @phpstan-ignore property.notFound */
                'metrics' => $prediction->metrics,
            ],
        ));

        // Continue if under max steps
        if ($this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    /**
     * Check if we should continue with tool calling loop.
     */
    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    /**
     * Build the prediction payload.
     *
     * @return array<string, mixed>
     */
    protected function buildPayload(Request $request): array
    {
        return [
            'version' => $this->extractVersionFromModel($request->model()),
            'input' => $this->buildInput($request),
        ];
    }

    /**
     * Build input parameters with tool support.
     *
     * @return array<string, mixed>
     */
    protected function buildInput(Request $request): array
    {
        $input = ['prompt' => MessageMap::map($request->messages())];

        // Build system prompt (with tool definitions if tools present)
        $systemPromptParts = [];

        // Add user system prompts
        if ($request->systemPrompts() !== []) {
            $systemPromptParts[] = implode("\n\n", array_map(
                fn ($prompt): string => $prompt->content,
                $request->systemPrompts()
            ));
        }

        // Add tool system prompt if tools are present
        if ($request->tools() !== []) {
            $systemPromptParts[] = ToolMap::buildSystemPrompt($request->tools());
        }

        // Combine system prompts
        if ($systemPromptParts !== []) {
            $input['system_prompt'] = implode("\n\n", $systemPromptParts);
        }

        // Add other parameters
        $params = $this->buildInputParameters($request);

        return array_merge($input, $params);
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
