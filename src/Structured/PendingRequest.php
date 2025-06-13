<?php

declare(strict_types=1);

namespace Prism\Prism\Structured;

use Illuminate\Support\Facades\Context;
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
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Telemetry\Events\StructuredOutputCompleted;
use Prism\Prism\Telemetry\Events\StructuredOutputStarted;
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
        $request = $this->toRequest();

        if (config('prism.telemetry.enabled', false)) {
            $spanId = Str::uuid()->toString();
            $parentSpanId = Context::get('prism.telemetry.current_span_id');
            $rootSpanId = Context::get('prism.telemetry.root_span_id') ?? $spanId;

            Context::add('prism.telemetry.current_span_id', $spanId);
            Context::add('prism.telemetry.root_span_id', $rootSpanId);

            Event::dispatch(new StructuredOutputStarted(
                spanId: $spanId,
                request: $request,
                context: [
                    'parent_span_id' => $parentSpanId,
                    'root_span_id' => $rootSpanId,
                ]
            ));

            try {
                $response = $this->provider->structured($request);

                Event::dispatch(new StructuredOutputCompleted(
                    spanId: $spanId,
                    request: $request,
                    response: $response,
                    context: [
                        'parent_span_id' => $parentSpanId,
                        'root_span_id' => $rootSpanId,
                    ]
                ));

                return $response;
            } finally {
                Context::add('prism.telemetry.current_span_id', $parentSpanId);
            }
        }

        return $this->provider->structured($request);
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
