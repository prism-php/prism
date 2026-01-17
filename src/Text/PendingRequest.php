<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

use Generator;
use Illuminate\Broadcasting\Channel;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresGeneration;
use Prism\Prism\Concerns\ConfiguresModels;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\ConfiguresTools;
use Prism\Prism\Concerns\EmitsTelemetry;
use Prism\Prism\Concerns\HasMessages;
use Prism\Prism\Concerns\HasPrompts;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Concerns\HasProviderTools;
use Prism\Prism\Concerns\HasTelemetryContext;
use Prism\Prism\Concerns\HasTools;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\Adapters\BroadcastAdapter;
use Prism\Prism\Streaming\Adapters\DataProtocolAdapter;
use Prism\Prism\Streaming\Adapters\SSEAdapter;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Telemetry\Events\StreamingCompleted;
use Prism\Prism\Telemetry\Events\StreamingStarted;
use Prism\Prism\Telemetry\Events\TextGenerationCompleted;
use Prism\Prism\Telemetry\Events\TextGenerationStarted;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresGeneration;
    use ConfiguresModels;
    use ConfiguresProviders;
    use ConfiguresTools;
    use EmitsTelemetry;
    use HasMessages;
    use HasPrompts;
    use HasProviderOptions;
    use HasProviderTools;
    use HasTelemetryContext;
    use HasTools;

    /**
     * @deprecated Use `asText` instead.
     *
     * @param  callable(PendingRequest, Response): void|null  $callback
     */
    public function generate(?callable $callback = null): Response
    {
        return $this->asText($callback);
    }

    /**
     * @param  callable(PendingRequest, Response): void|null  $callback
     */
    public function asText(?callable $callback = null): Response
    {
        $request = $this->toRequest();

        try {
            return $this->withTelemetry(
                startEventFactory: fn (string $spanId, string $traceId, ?string $parentSpanId): TextGenerationStarted => new TextGenerationStarted(
                    spanId: $spanId,
                    traceId: $traceId,
                    parentSpanId: $parentSpanId,
                    request: $request,
                ),
                endEventFactory: fn (string $spanId, string $traceId, ?string $parentSpanId, Response $response): TextGenerationCompleted => new TextGenerationCompleted(
                    spanId: $spanId,
                    traceId: $traceId,
                    parentSpanId: $parentSpanId,
                    request: $request,
                    response: $response,
                ),
                execute: function () use ($request, $callback): Response {
                    $response = $this->provider->text($request);

                    if ($callback !== null) {
                        $callback($this, $response);
                    }

                    return $response;
                },
            );
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function asStream(): Generator
    {
        $request = $this->toRequest();

        try {
            yield from $this->withStreamingTelemetry(
                startEventFactory: fn (string $spanId, string $traceId, ?string $parentSpanId, ?StreamStartEvent $streamStart): StreamingStarted => new StreamingStarted(
                    spanId: $spanId,
                    traceId: $traceId,
                    parentSpanId: $parentSpanId,
                    request: $request,
                    streamStart: $streamStart,
                ),
                endEventFactory: fn (string $spanId, string $traceId, ?string $parentSpanId, ?StreamEndEvent $streamEnd): StreamingCompleted => new StreamingCompleted(
                    spanId: $spanId,
                    traceId: $traceId,
                    parentSpanId: $parentSpanId,
                    request: $request,
                    streamEnd: $streamEnd,
                ),
                execute: fn (): Generator => $this->provider->stream($request),
                startEventType: StreamStartEvent::class,
                endEventType: StreamEndEvent::class,
            );
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    /**
     * @param  callable(PendingRequest, Collection<int, StreamEvent>): void|null  $callback
     */
    public function asDataStreamResponse(?callable $callback = null): StreamedResponse
    {
        return (new DataProtocolAdapter)($this->asStream(), $this, $callback);
    }

    /**
     * @param  callable(PendingRequest, Collection<int, StreamEvent>): void|null  $callback
     */
    public function asEventStreamResponse(?callable $callback = null): StreamedResponse
    {
        return (new SSEAdapter)($this->asStream(), $this, $callback);
    }

    /**
     * @param  Channel|Channel[]  $channels
     * @param  callable(PendingRequest, Collection<int, StreamEvent>): void|null  $callback
     */
    public function asBroadcast(Channel|array $channels, ?callable $callback = null): void
    {
        (new BroadcastAdapter($channels))($this->asStream(), $this, $callback);
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

        $tools = $this->tools;

        if (! $this->toolErrorHandlingEnabled && filled($tools)) {
            $tools = array_map(
                callback: fn (Tool $tool): Tool => is_null($tool->failedHandler()) ? $tool : $tool->withoutErrorHandling(),
                array: $tools
            );
        }

        return new Request(
            model: $this->model,
            providerKey: $this->providerKey(),
            systemPrompts: $this->systemPrompts,
            prompt: $this->prompt,
            messages: $messages,
            maxSteps: $this->maxSteps,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            topP: $this->topP,
            tools: $tools,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            toolChoice: $this->toolChoice,
            providerOptions: $this->providerOptions,
            providerTools: $this->providerTools,
        );
    }
}
