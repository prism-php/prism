<?php

declare(strict_types=1);

namespace Prism\Prism\Structured;

use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresGeneration;
use Prism\Prism\Concerns\ConfiguresModels;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\ConfiguresStructuredOutput;
use Prism\Prism\Concerns\ConfiguresTools;
use Prism\Prism\Concerns\EmitsTelemetry;
use Prism\Prism\Concerns\HasMessages;
use Prism\Prism\Concerns\HasPrompts;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Concerns\HasProviderTools;
use Prism\Prism\Concerns\HasSchema;
use Prism\Prism\Concerns\HasTelemetryContext;
use Prism\Prism\Concerns\HasTools;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Telemetry\Events\StructuredOutputCompleted;
use Prism\Prism\Telemetry\Events\StructuredOutputStarted;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresGeneration;
    use ConfiguresModels;
    use ConfiguresProviders;
    use ConfiguresStructuredOutput;
    use ConfiguresTools;
    use EmitsTelemetry;
    use HasMessages;
    use HasPrompts;
    use HasProviderOptions;
    use HasProviderTools;
    use HasSchema;
    use HasTelemetryContext;
    use HasTools;

    /**
     * @deprecated Use `asStructured` instead.
     */
    public function generate(): Response
    {
        return $this->asStructured();
    }

    public function asStructured(): Response
    {
        $request = $this->toRequest();

        try {
            return $this->withTelemetry(
                startEventFactory: fn (string $spanId, string $traceId, ?string $parentSpanId): \Prism\Prism\Telemetry\Events\StructuredOutputStarted => new StructuredOutputStarted(
                    spanId: $spanId,
                    traceId: $traceId,
                    parentSpanId: $parentSpanId,
                    request: $request,
                ),
                endEventFactory: fn (string $spanId, string $traceId, ?string $parentSpanId, Response $response): \Prism\Prism\Telemetry\Events\StructuredOutputCompleted => new StructuredOutputCompleted(
                    spanId: $spanId,
                    traceId: $traceId,
                    parentSpanId: $parentSpanId,
                    request: $request,
                    response: $response,
                ),
                execute: fn (): \Prism\Prism\Structured\Response => $this->provider->structured($request),
            );
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    public function toRequest(): Request
    {
        if ($this->messages && $this->prompt) {
            throw PrismException::promptOrMessages();
        }

        $messages = $this->messages;

        if ($this->prompt) {
            $messages[] = new UserMessage($this->prompt, $this->additionalContent);
        }

        if (! $this->schema instanceof Schema) {
            throw new PrismException('A schema is required for structured output');
        }

        return new Request(
            systemPrompts: $this->systemPrompts,
            model: $this->model,
            providerKey: $this->providerKey(),
            prompt: $this->prompt,
            messages: $messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            topP: $this->topP,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            schema: $this->schema,
            mode: $this->structuredMode,
            tools: $this->tools,
            toolChoice: $this->toolChoice,
            maxSteps: $this->maxSteps,
            providerOptions: $this->providerOptions,
            providerTools: $this->providerTools,
        );
    }
}
