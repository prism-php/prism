<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

use Closure;
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
use Prism\Prism\Concerns\HasTools;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\Adapters\BroadcastAdapter;
use Prism\Prism\Streaming\Adapters\DataProtocolAdapter;
use Prism\Prism\Streaming\Adapters\SSEAdapter;
use Prism\Prism\Streaming\StreamCollector;
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
    use HasMessages;
    use HasPrompts;
    use HasProviderOptions;
    use HasProviderTools;
    use HasTools;

    protected ?Closure $completeCallback = null;

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

        try {
            $response = $this->provider->text($request);

            if ($this->completeCallback instanceof Closure) {
                ($this->completeCallback)($this, $response->messages);
            }

            return $response;
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    /**
     * @param  callable(PendingRequest|null, Collection<int, Message>): void  $callback
     */
    public function onComplete(callable $callback): self
    {
        $this->completeCallback = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);

        return $this;
    }

    /**
     * @return Generator<\Prism\Prism\Streaming\Events\StreamEvent>
     */
    public function asStream(): Generator
    {
        $request = $this->toRequest();

        try {
            $chunks = $this->provider->stream($request);

            if ($this->completeCallback instanceof Closure) {
                $collector = new StreamCollector($chunks, $this, $this->completeCallback);

                yield from $collector->collect();
            } else {
                foreach ($chunks as $chunk) {
                    yield $chunk;
                }
            }
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    public function asDataStreamResponse(): StreamedResponse
    {
        return (new DataProtocolAdapter)($this->asStream());
    }

    public function asEventStreamResponse(): StreamedResponse
    {
        return (new SSEAdapter)($this->asStream());
    }

    /**
     * @param  Channel|Channel[]  $channels
     */
    public function asBroadcast(Channel|array $channels): void
    {
        (new BroadcastAdapter($channels))($this->asStream());
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
