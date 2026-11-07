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
use Prism\Prism\Concerns\HasMessages;
use Prism\Prism\Concerns\HasPrompts;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Concerns\HasProviderTools;
use Prism\Prism\Concerns\HasReasoning;
use Prism\Prism\Concerns\HasTelemetryMetadata;
use Prism\Prism\Concerns\HasTools;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\Adapters\BroadcastAdapter;
use Prism\Prism\Streaming\Adapters\DataProtocolAdapter;
use Prism\Prism\Streaming\Adapters\SSEAdapter;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Support\CacheHints;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
    use HasReasoning;
    use HasTelemetryMetadata;
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

        $context = Telemetry::start(TelemetryOperation::Text, $this->providerKey(), $request->model(), $request, $this->telemetryUserId, $this->telemetrySessionId);

        try {
            $response = $this->provider->text($request);

            Telemetry::completed($context, $response, $response->finishReason, $response->usage);

            if ($callback !== null) {
                $callback($this, $response);
            }

            return $response;
        } catch (RequestException $e) {
            Telemetry::failed($context, $e);

            $this->provider->handleRequestException($request->model(), $e);
        } finally {
            Telemetry::end($context);
        }
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function asStream(): Generator
    {
        $failureUsage = null;
        $request = $this->toRequest();

        $context = Telemetry::start(TelemetryOperation::Stream, $this->providerKey(), $request->model(), $request, $this->telemetryUserId, $this->telemetrySessionId);

        try {
            $stream = $this->provider->stream($request);

            $events = $context instanceof TelemetryContext
                ? Telemetry::instrumentStream($context, $stream, $request)
                : $stream;
            while ($events->valid()) {
                $key = $events->key();
                $event = $events->current();
                if ($event instanceof ErrorEvent && $event->errorType === 'stream_read_failed') {
                    $failureUsage = $event->failureUsage;
                }
                try {
                    $sent = yield $key => $event;
                } catch (Throwable $consumerException) { // @phpstan-ignore catch.neverThrown (Generator::throw() injects at yield; covered by published compatibility tests.)
                    $events->throw($consumerException);

                    continue;
                }
                $events->send($sent);
            }
        } catch (RequestException $e) {
            Telemetry::failed($context, $e);

            try {
                $this->provider->handleRequestException($request->model(), $e);
            } catch (PrismException $mapped) {
                throw $mapped->withStreamFailureUsage($failureUsage);
            }
        } catch (Throwable $e) {
            Telemetry::failed($context, $e);

            throw $e;
        } finally {
            Telemetry::end($context);
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
        if ($this->messages && $this->hasPrompt()) {
            throw PrismException::promptOrMessages();
        }

        $messages = [...$this->threadMessages(), ...$this->messages];

        if ($this->hasPrompt()) {
            $messages[] = new UserMessage($this->prompt, $this->additionalContent);
        }

        CacheHints::warnIfMisordered($messages);

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
            topK: $this->topK,
            tools: $tools,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            toolChoice: $this->toolChoice,
            providerOptions: $this->providerOptions,
            providerTools: $this->providerTools,
            reasoningEnabled: $this->reasoningEnabled,
        );
    }
}
