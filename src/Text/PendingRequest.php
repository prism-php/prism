<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

use Generator;
use Illuminate\Support\Facades\Context;
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
use Prism\Prism\Concerns\HasProviderTools;
use Prism\Prism\Concerns\HasTools;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Telemetry\Events\StreamingCompleted;
use Prism\Prism\Telemetry\Events\StreamingStarted;
use Prism\Prism\Telemetry\Events\TextGenerationCompleted;
use Prism\Prism\Telemetry\Events\TextGenerationStarted;
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
    use HasProviderTools;
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
        $request = $this->toRequest();

        if (config('prism.telemetry.enabled', false)) {
            $spanId = Str::uuid()->toString();
            $parentSpanId = Context::get('prism.telemetry.current_span_id');
            $rootSpanId = Context::get('prism.telemetry.root_span_id') ?? $spanId;

            Context::add('prism.telemetry.current_span_id', $spanId);
            Context::add('prism.telemetry.root_span_id', $rootSpanId);

            Event::dispatch(new TextGenerationStarted(
                spanId: $spanId,
                request: $request,
                context: [
                    'parent_span_id' => $parentSpanId,
                    'root_span_id' => $rootSpanId,
                ]
            ));

            try {
                $response = $this->provider->text($request);

                Event::dispatch(new TextGenerationCompleted(
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

        return $this->provider->text($request);
    }

    /**
     * @return Generator<Chunk>
     */
    public function asStream(): Generator
    {
        $request = $this->toRequest();

        if (config('prism.telemetry.enabled', false)) {
            $spanId = Str::uuid()->toString();
            $parentSpanId = Context::get('prism.telemetry.current_span_id');
            $rootSpanId = Context::get('prism.telemetry.root_span_id') ?? $spanId;

            Context::add('prism.telemetry.current_span_id', $spanId);
            Context::add('prism.telemetry.root_span_id', $rootSpanId);

            Event::dispatch(new StreamingStarted(
                spanId: $spanId,
                request: $request,
                context: [
                    'parent_span_id' => $parentSpanId,
                    'root_span_id' => $rootSpanId,
                ]
            ));

            try {
                foreach ($this->provider->stream($request) as $chunk) {
                    yield $chunk;
                }

                Event::dispatch(new StreamingCompleted(
                    spanId: $spanId,
                    request: $request,
                    context: [
                        'parent_span_id' => $parentSpanId,
                        'root_span_id' => $rootSpanId,
                    ]
                ));
            } finally {
                Context::add('prism.telemetry.current_span_id', $parentSpanId);
            }

            return;
        }

        yield from $this->provider->stream($request);
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
            providerTools: $this->providerTools,
        );
    }
}
