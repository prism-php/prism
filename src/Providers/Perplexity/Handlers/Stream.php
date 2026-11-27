<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Perplexity\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Prism\Concerns\TracksStreamFailureUsage;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsAdditionalContent;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsAgentResponse;
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
use Prism\Prism\Streaming\StreamFailureUsageTracker;
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
    use ExtractsAgentResponse;
    use ExtractsFinishReason;
    use ExtractsMeta;
    use ExtractsUsage;
    use HandlesHttpRequests;
    use TracksStreamFailureUsage;

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
        $this->streamFailureTracker = null;

        $response = $this->sendRequest($this->client, $request, true);

        yield from $this->processStream($response, $request);
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function processStream(Response $response, Request $request): Generator
    {
        $this->streamFailureTracker = new StreamFailureUsageTracker('perplexity', 0);

        $this->state->reset();
        $text = '';
        $finishReason = null;
        $stepUsage = null;

        while (! (yield from $this->readStreamValue(fn (): bool => $response->getBody()->eof()))) {
            $data = yield from $this->readStreamValue(fn (): ?array => $this->parseNextDataLine($response->getBody()));

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

            $terminal = $this->isStreamTerminal($data);
            $run = $terminal ? $this->streamRunData($data) : [];

            // The Agent API carries usage on the terminal run snapshot; Sonar-shaped
            // chunks carry the running usage on every chunk. The latest one wins.
            $stepUsage = $this->extractStepUsage($terminal ? $run : $data) ?? $stepUsage;

            // Once the run has ended (an unrecoverable error or its first terminal),
            // later chunks only update usage: content after the ending is not part
            // of the run, and a repeated terminal must not end the stream twice.
            if ($finishReason instanceof FinishReason) {
                continue;
            }

            // Rate limiting is recoverable even when attached to a terminal
            // run snapshot. Preserve the caller's existing backoff exception.
            if ($this->isRateLimitError($data) || $this->isRateLimitError($run)) {
                throw (new PrismRateLimitedException([]))->withStreamFailureUsage($this->streamFailureUsage());
            }

            if ($terminal && in_array($run['status'] ?? null, ['incomplete', 'failed', 'cancelled', 'run_incomplete', 'run_failed'], true)) {
                // Earlier deltas are provisional. Throw with the full snapshot
                // before emitting completion events or handling generic errors.
                try {
                    $this->assertRunSucceeded($run, $response->header('X-Request-ID') ?: null);
                } catch (PrismException $exception) {
                    throw $exception->withStreamFailureUsage($this->streamFailureUsage());
                }
            }

            // Unknown non-progress statuses retain the historical Unknown
            // ending; they are not evidence of one of the known run failures.

            // Handle error chunks per Perplexity streaming guide
            if ($this->hasError($data)) {
                yield from $this->handleErrors($data, $request);

                $finishReason = FinishReason::Error;

                continue;
            }

            $content = $this->extractsStreamDelta($data);

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

            // The run can report its ending more than once (a terminal event, then a
            // trailing done chunk), so the first one wins and the stream ends once,
            // after the body is read.
            if ($terminal) {
                $finishReason = $this->extractsFinishReason($run);
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
            throw (new PrismStreamDecodeException(ProviderEnum::Perplexity->value, $e))->withStreamFailureUsage($this->streamFailureUsage());
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
     */
    protected function isRateLimitError(array $data): bool
    {
        return data_get($data, 'error.type') === 'rate_limit_exceeded';
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
        if ($this->isRateLimitError($data)) {
            throw (new PrismRateLimitedException([]))->withStreamFailureUsage($this->streamFailureUsage());
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
            ],
            failureUsage: $this->streamFailureUsage(),
        );
    }
}
