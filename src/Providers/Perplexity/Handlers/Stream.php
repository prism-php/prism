<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Perplexity\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsAdditionalContent;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsFinishReason;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsMeta;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsUsage;
use Prism\Prism\Providers\Perplexity\Concerns\HandlesHttpRequests;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * @link https://docs.perplexity.ai/guides/streaming-responses
 */
class Stream
{
    use ExtractsAdditionalContent;
    use ExtractsFinishReason;
    use ExtractsMeta;
    use ExtractsUsage;
    use HandlesHttpRequests;

    protected StreamState $state;

    public function __construct(
        protected PendingRequest $client,
    ) {
        $this->state = new StreamState;
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function handle(Request $request): Generator
    {
        $response = $this->sendRequest($this->client, $request, true);

        yield from $this->processStream($response, $request);
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function processStream(Response $response, Request $request): Generator
    {
        $this->state->reset();
        $text = '';
        $finishReason = null;
        $stepUsage = null;

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            if ($this->state->shouldEmitStreamStart()) {
                $this->state->withMessageId(EventID::generate())->markStreamStarted();

                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: $request->model(),
                    provider: ProviderEnum::Perplexity->value
                );
            }

            if ($this->state->shouldEmitStepStart()) {
                $this->state->markStepStarted();

                yield new StepStartEvent(
                    id: EventID::generate(),
                    timestamp: time()
                );
            }

            // Handle error chunks per Perplexity streaming guide
            if ($this->hasError($data)) {
                yield from $this->handleErrors($data, $request);

                // Do not process further content in this chunk
                continue;
            }

            $stepUsage = $this->extractStepUsage($data) ?? $stepUsage;

            $content = data_get($data, 'choices.0.delta.content', '') ?? '';

            if ($content !== '') {
                if ($this->state->shouldEmitTextStart()) {
                    $this->state->markTextStarted();

                    yield new TextStartEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        messageId: $this->state->messageId()
                    );
                }

                $text .= $content;

                yield new TextDeltaEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $content,
                    messageId: $this->state->messageId()
                );
            }

            // Perplexity can repeat the finish reason (e.g. on a trailing `chat.completion.done`
            // chunk), so the first one wins and the stream ends once, after the body is read.
            if (! $finishReason instanceof FinishReason && $this->hasFinishReason($data)) {
                $finishReason = $this->extractsFinishReason($data);
            }
        }

        if ($text !== '' && $this->state->hasTextStarted()) {
            $this->state->markTextCompleted();

            yield new TextCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $this->state->messageId()
            );
        }

        if ($stepUsage instanceof Usage) {
            $this->state->addUsage($stepUsage);
        }

        $this->state->withFinishReason($finishReason ?? FinishReason::Stop);
        $this->state->markStepFinished();

        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time(),
            usage: $stepUsage,
            finishReason: $this->state->finishReason(),
        );

        yield new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: $this->state->finishReason() ?? FinishReason::Stop,
            usage: $this->state->usage() ?? new Usage(0, 0),
        );
    }

    /**
     * Every chunk carries the response's running usage, so the latest one wins.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractStepUsage(array $data): ?Usage
    {
        $usage = data_get($data, 'usage');

        if (! is_array($usage) || $usage === []) {
            return null;
        }

        return $this->extractUsage($data);
    }

    /**
     * @return array<string, mixed>|null Parsed JSON data or null if line should be skipped
     *
     * @throws PrismStreamDecodeException
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $line = trim(substr($line, strlen('data: ')));

        if ($line === '' || $line === '[DONE]') {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException(ProviderEnum::Perplexity->value, $e);
        }
    }

    protected function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (! $stream->eof()) {
            $byte = $stream->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasError(array $data): bool
    {
        return data_get($data, 'error') !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Generator<StreamEvent>
     *
     * @throws PrismRateLimitedException
     */
    protected function handleErrors(array $data, Request $request): Generator
    {
        $error = data_get($data, 'error', []);
        $type = (string) data_get($error, 'type', 'unknown_error');
        $message = (string) data_get($error, 'message', 'No error message provided');

        // If rate limit, throw so caller can handle retry semantics
        if ($type === 'rate_limit_exceeded') {
            throw new PrismRateLimitedException([]);
        }

        // Non-rate-limit errors are emitted as events (not recoverable) and we stop processing further content from this chunk
        yield new ErrorEvent(
            id: EventID::generate(),
            timestamp: time(),
            errorType: $type,
            message: $message,
            recoverable: false,
            metadata: [
                'model' => $request->model(),
            ]
        );
    }
}
