<?php

declare(strict_types=1);

namespace Prism\Prism\Structured;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresModels;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\ConfiguresStructuredOutput;
use Prism\Prism\Concerns\HasMessages;
use Prism\Prism\Concerns\HasPrompts;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Concerns\HasSchema;
use Prism\Prism\Events\PrismRequestCompleted;
use Prism\Prism\Events\PrismRequestStarted;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresModels;
    use ConfiguresProviders;
    use ConfiguresStructuredOutput;
    use HasMessages;
    use HasPrompts;
    use HasProviderOptions;
    use HasSchema;

    /**
     * @deprecated Use `asStructured` instead.
     */
    public function generate(): Response
    {
        return $this->asStructured();
    }

    public function asStructured(): Response
    {
        $contextId = Str::uuid()->toString();

        Event::dispatch(new PrismRequestStarted(
            contextId: $contextId,
            operationName: 'structured_output',
            attributes: [
                'provider' => $this->provider::class,
                'model' => $this->model,
                'schema' => $this->schema instanceof \Prism\Prism\Contracts\Schema ? $this->schema::class : null,
                'message_count' => count($this->messages ?? []),
                'structured_mode' => $this->structuredMode->name,
            ]
        ));

        try {
            $request = $this->toRequest();
            $request->setTelemetryContextId($contextId);
            $response = $this->provider->structured($request);

            Event::dispatch(new PrismRequestCompleted(
                contextId: $contextId,
                attributes: [
                    'finish_reason' => $response->finishReason->name,
                    'usage_prompt_tokens' => $response->usage->promptTokens,
                    'usage_completion_tokens' => $response->usage->completionTokens,
                ]
            ));

            return $response;
        } catch (\Throwable $e) {
            Event::dispatch(new PrismRequestCompleted(
                contextId: $contextId,
                exception: $e
            ));

            throw $e;
        }
    }

    public function toRequest(): Request
    {
        if ($this->messages && $this->prompt) {
            throw PrismException::promptOrMessages();
        }

        $messages = $this->messages;

        if ($this->prompt) {
            $messages[] = new UserMessage($this->prompt);
        }

        if (! $this->schema instanceof \Prism\Prism\Contracts\Schema) {
            throw new PrismException('A schema is required for structured output');
        }

        return new Request(
            model: $this->model,
            systemPrompts: $this->systemPrompts,
            prompt: $this->prompt,
            messages: $messages,
            temperature: $this->temperature,
            maxTokens: $this->maxTokens,
            topP: $this->topP,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            providerOptions: $this->providerOptions,
            schema: $this->schema,
            mode: $this->structuredMode,
        );
    }
}
