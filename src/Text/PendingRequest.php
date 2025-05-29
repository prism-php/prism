<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

use Generator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresGeneration;
use Prism\Prism\Concerns\ConfiguresModels;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\ConfiguresTools;
use Prism\Prism\Concerns\HasMessages;
use Prism\Prism\Concerns\HasPrompts;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Concerns\HasTools;
use Prism\Prism\Events\PrismRequestCompleted;
use Prism\Prism\Events\PrismRequestStarted;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresGeneration;
    use ConfiguresModels;
    use ConfiguresProviders;
    use ConfiguresTools;
    use HasMessages;
    use HasPrompts;
    use HasProviderOptions;
    use HasTools;

    /**
     * @deprecated Use `asText` instead.
     */
    public function generate(): Response
    {
        return $this->asText();
    }

    public function asText(): Response
    {
        $contextId = Str::uuid()->toString();

        Event::dispatch(new PrismRequestStarted(
            contextId: $contextId,
            operationName: 'text_generation',
            attributes: [
                'provider' => $this->provider::class,
                'model' => $this->model,
                'has_tools' => $this->tools !== [],
                'message_count' => count($this->messages ?? []),
            ]
        ));

        try {
            $request = $this->toRequest();
            $request->setTelemetryContextId($contextId);
            $response = $this->provider->text($request);

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

    /**
     * @return Generator<Chunk>
     */
    public function asStream(): Generator
    {
        $contextId = Str::uuid()->toString();

        Event::dispatch(new PrismRequestStarted(
            contextId: $contextId,
            operationName: 'text_generation_stream',
            attributes: [
                'provider' => $this->provider::class,
                'model' => $this->model,
                'has_tools' => $this->tools !== [],
                'message_count' => count($this->messages ?? []),
                'streaming' => true,
            ]
        ));

        try {
            $request = $this->toRequest();
            $request->setTelemetryContextId($contextId);
            $stream = $this->provider->stream($request);
            $lastChunk = null;

            foreach ($stream as $chunk) {
                $lastChunk = $chunk;
                yield $chunk;
            }

            // Extract final response data from the last chunk
            $attributes = [];
            if ($lastChunk?->finishReason) {
                $attributes['finish_reason'] = $lastChunk->finishReason->name;
            }
            // Note: Usage info may not be available in streaming chunks
            // This would need to be implemented per-provider based on their streaming response format

            Event::dispatch(new PrismRequestCompleted(
                contextId: $contextId,
                attributes: $attributes
            ));
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

        return new Request(
            model: $this->model,
            systemPrompts: $this->systemPrompts,
            prompt: $this->prompt,
            messages: $messages,
            temperature: $this->temperature,
            maxTokens: $this->maxTokens,
            maxSteps: $this->maxSteps,
            topP: $this->topP,
            tools: $this->tools,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            toolChoice: $this->toolChoice,
            providerOptions: $this->providerOptions,
        );
    }
}
