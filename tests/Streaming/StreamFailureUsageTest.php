<?php

use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Broadcasting\Channel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Events\Broadcasting\ErrorBroadcast;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationErrored;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRunException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Azure\Azure;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Providers\Replicate\Handlers\Stream;
use Prism\Prism\Streaming\Adapters\DataProtocolAdapter;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\StreamFailureUsageTracker;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\StreamFailureUsage;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;

function failedUsageSse(array $data): string
{
    return 'data: '.json_encode($data, JSON_THROW_ON_ERROR)."\n\n";
}

it('treats an XAI total without attributable input or output as unreported', function (): void {
    $tracker = new StreamFailureUsageTracker('xai');
    $tracker->record(['usage' => ['total_tokens' => 14]]);
    expect($tracker->snapshot())->toBeNull();
});

it('does not add an error event when a foreign read fails without reported usage', function (): void {
    $sentinel = new RuntimeException('owned unreported failure');
    $body = new class(Utils::streamFor(failedUsageStart('Anthropic', false)), $sentinel) implements StreamInterface
    {
        use StreamDecoratorTrait;

        public function __construct(StreamInterface $stream, private readonly Throwable $failure)
        {
            $this->stream = $stream;
        }

        public function eof(): bool
        {
            return false;
        }

        public function read($length): string
        {
            if ($this->stream->eof()) {
                throw $this->failure;
            }

            return $this->stream->read($length);
        }
    };
    $handler = failedUsageHandler('Anthropic');
    $events = [];
    $caught = null;
    try {
        foreach ((new ReflectionMethod($handler, 'processStream'))->invoke($handler, new Response(new PsrResponse(200, [], $body)), new Request(model: 'owned')) as $event) {
            $events[] = $event;
        }
    } catch (Throwable $failure) {
        $caught = $failure;
    }
    expect($caught)->toBe($sentinel)
        ->and(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ErrorEvent))->toBeEmpty();
});

function failedUsageStart(string $provider, bool $reported = true): string
{
    $usage = ['prompt_tokens' => 11, 'completion_tokens' => 3];
    if ($provider === 'Anthropic') {
        return failedUsageSse(['type' => 'message_start', 'message' => ['id' => 'owned', 'model' => 'owned', 'content' => [], ...($reported ? ['usage' => ['input_tokens' => 11, 'output_tokens' => 3, 'cache_creation_input_tokens' => 5, 'cache_read_input_tokens' => 7, 'cache_creation' => ['ephemeral_5m_input_tokens' => 2, 'ephemeral_1h_input_tokens' => 3]]] : [])]]);
    }
    if (in_array($provider, ['Gemini', 'Vertex'], true)) {
        return failedUsageSse(['modelVersion' => 'owned', 'candidates' => [['index' => 0, 'content' => ['parts' => [['text' => 'x']]]]], ...($reported ? ['usageMetadata' => ['promptTokenCount' => 11, 'candidatesTokenCount' => 3]] : [])]);
    }
    if ($provider === 'OpenAI') {
        return failedUsageSse(['type' => 'response.created', 'response' => ['model' => 'owned', ...($reported ? ['usage' => ['input_tokens' => 11, 'output_tokens' => 3]] : [])]]);
    }
    if ($provider === 'Ollama') {
        return json_encode(['model' => 'owned', 'message' => ['content' => 'x'], 'done' => false, ...($reported ? ['prompt_eval_count' => 11, 'eval_count' => 3] : [])])."\n";
    }
    if ($provider === 'Qwen') {
        return failedUsageSse(['output' => ['choices' => [['message' => ['content' => 'x'], 'finish_reason' => 'null']]], ...($reported ? ['usage' => ['input_tokens' => 11, 'output_tokens' => 3]] : [])]);
    }

    return failedUsageSse(['id' => 'owned', 'model' => 'owned', 'choices' => [['index' => 0, 'delta' => ['content' => 'x'], 'finish_reason' => null]], ...($reported ? ['usage' => $usage, 'x_groq' => ['usage' => $usage]] : [])]);
}

function failedUsageHandler(string $provider): object
{
    $class = $provider === 'OpenAIChat' ? \Prism\Prism\Providers\OpenAI\Handlers\ChatCompletions\Stream::class : 'Prism\\Prism\\Providers\\'.$provider.'\\Handlers\\Stream';
    $client = Http::baseUrl('https://offline.invalid');

    return match ($provider) {
        'Mistral', 'Gemini' => new $class($client, 'owned-fake'),
        'Vertex' => new $class($client, 'owned'),
        'Azure' => new $class($client, new Azure('https://offline.invalid', 'owned-fake', '2024-10-21')),
        default => new $class($client),
    };
}

dataset('failed usage decoders', ['Anthropic', 'Azure', 'DeepSeek', 'Gemini', 'Groq', 'Mistral', 'Ollama', 'OpenAI', 'OpenAIChat', 'OpenRouter', 'Perplexity', 'Qwen', 'Requesty', 'Vertex', 'XAI', 'Z']);

it('yields one usage error before rethrowing the identical foreign read exception', function (string $provider): void {
    Http::preventStrayRequests();
    $sentinel = new RuntimeException('private transport diagnostic must not enter an event');
    $stream = new class(Utils::streamFor(failedUsageStart($provider)), $sentinel) implements StreamInterface
    {
        use StreamDecoratorTrait;

        public function __construct(StreamInterface $stream, private readonly Throwable $failure)
        {
            $this->stream = $stream;
        }

        public function eof(): bool
        {
            return false;
        }

        public function read($length): string
        {
            if ($this->stream->eof()) {
                throw $this->failure;
            }

            return $this->stream->read($length);
        }
    };
    $handler = failedUsageHandler($provider);
    $request = new Request(model: 'owned', messages: [new UserMessage('owned')], maxTokens: 32);
    $events = [];
    $caught = null;
    try {
        foreach ((new ReflectionMethod($handler, 'processStream'))->invoke($handler, new Response(new PsrResponse(200, [], $stream)), $request) as $event) {
            $events[] = $event;
        }
    } catch (Throwable $exception) {
        $caught = $exception;
    }
    $errors = array_values(array_filter($events, fn ($e): bool => $e instanceof ErrorEvent));
    expect($caught)->toBe($sentinel)
        ->and($errors)->toHaveCount(1)
        ->and(end($events))->toBe($errors[0])
        ->and($errors[0]->failureUsage->usage->promptTokens)->toBe(11)
        ->and($errors[0]->failureUsage->usage->completionTokens)->toBe(3)
        ->and($errors[0]->failureUsage->status)->toBe('provisional')
        ->and($errors[0]->failureUsage->includesCompletedSteps)->toBeFalse()
        ->and(json_encode($errors[0]->toArray()))->not->toContain('private transport');
})->with('failed usage decoders');

it('enriches SDK decode exceptions without replacing their cause or inventing completion', function (string $provider): void {
    Http::preventStrayRequests();
    $handler = failedUsageHandler($provider);
    $body = failedUsageStart($provider).($provider === 'Ollama' ? "{broken\n" : "data: {broken\n\n");
    $events = [];
    $caught = null;
    try {
        foreach ((new ReflectionMethod($handler, 'processStream'))->invoke($handler, new Response(new PsrResponse(200, [], $body)), new Request(model: 'owned', messages: [new UserMessage('owned')], maxTokens: 32)) as $event) {
            $events[] = $event;
        }
    } catch (PrismStreamDecodeException $exception) {
        $caught = $exception;
    }
    expect($caught)->toBeInstanceOf(PrismStreamDecodeException::class)
        ->and($caught->getPrevious())->toBeInstanceOf(JsonException::class)
        ->and($caught->streamFailureUsage()->usage->promptTokens)->toBe(11)
        ->and(array_filter($events, fn ($e): bool => $e instanceof StreamEndEvent))->toBeEmpty()
        ->and(json_decode(json_encode($caught), true)['failure_usage']['status'])->toBe('provisional');
})->with('failed usage decoders');

it('omits absent failure usage from legacy error JSON and keeps private exceptions private', function (): void {
    $event = new ErrorEvent('id', 123, 'error', 'owned', false);
    expect($event->toArray())->toBe(['id' => 'id', 'timestamp' => 123, 'error_type' => 'error', 'message' => 'owned', 'recoverable' => false, 'metadata' => null])
        ->and(json_encode(new PrismException('private exception message')))->toBe('{"httpStatus":null,"responseBody":null}')
        ->and(json_encode($event))->not->toContain('failure_usage', 'failureUsage');
    $e = new PrismRunException('run_failed', 'owned', ['secret' => 'do-not-serialize'], null);
    expect(json_encode($e))->not->toContain('do-not-serialize', 'runData', 'failure_usage');
});

it('distinguishes missing empty malformed and explicit zero reports', function (): void {
    $tracker = new StreamFailureUsageTracker('anthropic');
    foreach ([[], ['usage' => []], ['usage' => ['input_tokens' => '0', 'output_tokens' => -1]], ['usage' => ['input_tokens' => INF]]] as $data) {
        $tracker->record($data);
        expect($tracker->snapshot())->toBeNull();
    }
    $tracker->record(['usage' => ['input_tokens' => 0, 'cache_read_input_tokens' => 0]]);
    $snapshot = $tracker->snapshot();
    expect($snapshot->usage->promptTokens)->toBe(0)
        ->and($snapshot->reportedFields)->toBe(['prompt_tokens', 'cache_read_input_tokens'])
        ->and($snapshot->usage->cacheReadInputTokens)->toBe(0);
    $tracker->record(['usage' => []]);
    expect($tracker->snapshot()->toArray())->toBe($snapshot->toArray());
});

it('reconciles cumulative reports and invalidates cache splits when a changed total does not report them', function (): void {
    $tracker = new StreamFailureUsageTracker('anthropic', 2);
    $tracker->record(['message' => ['usage' => ['input_tokens' => 11, 'output_tokens' => 3, 'cache_creation_input_tokens' => 5, 'cache_creation' => ['ephemeral_5m_input_tokens' => 2, 'ephemeral_1h_input_tokens' => 3]]]]);
    $tracker->record(['usage' => ['output_tokens' => 6]]);
    $tracker->record(['usage' => ['output_tokens' => 6]]);
    expect($tracker->snapshot()->usage->promptTokens)->toBe(11)
        ->and($tracker->snapshot()->usage->completionTokens)->toBe(6)
        ->and($tracker->snapshot()->usage->cacheWrite1hInputTokens)->toBe(3)
        ->and($tracker->snapshot()->stepIndex)->toBe(2);
    $tracker->record(['usage' => ['cache_creation_input_tokens' => 8]]);
    expect($tracker->snapshot()->usage->cacheWriteInputTokens)->toBe(8)
        ->and($tracker->snapshot()->usage->cacheWrite5mInputTokens)->toBeNull()
        ->and($tracker->snapshot()->reportedFields)->not->toContain('cache_write_1h_input_tokens');
    expect((new StreamFailureUsageTracker('anthropic', 3))->snapshot())->toBeNull();
});

it('keeps zero cost and canonical full usage fields in a failed terminal snapshot', function (): void {
    $tracker = new StreamFailureUsageTracker('perplexity');
    $tracker->record(['response' => ['usage' => ['input_tokens' => 11, 'output_tokens' => 3, 'cost' => ['total_cost' => 0]]]]);
    expect($tracker->snapshot()->usage->cost)->toBe(0.0)
        ->and($tracker->snapshot()->toArray()['usage'])->toHaveKeys(array_keys((new Usage(0, 0))->toArray()));
});

it('reports event failures as errored telemetry with provisional usage and no content', function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'owned-fake');
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', false);
    $seen = [];
    app('events')->listen([GenerationCompleted::class, GenerationErrored::class, GenerationFailed::class], function ($event) use (&$seen): void {
        $seen[] = $event;
    });
    Http::fake(['*' => Http::response(failedUsageStart('Anthropic').failedUsageSse(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'private body']]), 200)]);
    $events = iterator_to_array(Prism::text()->using('anthropic', 'owned')->withPrompt('private prompt')->asStream());
    expect(array_filter($seen, fn ($e): bool => $e instanceof GenerationCompleted))->toBeEmpty()
        ->and($seen)->toHaveCount(1)
        ->and($seen[0])->toBeInstanceOf(GenerationErrored::class)
        ->and($seen[0]->failureUsage->usage->promptTokens)->toBe(11)
        ->and($seen[0]->failureUsage->usage->cacheWrite5mInputTokens)->toBe(2)
        ->and(json_encode($seen[0]))->not->toContain('private body', 'private prompt')
        ->and(array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof ErrorEvent))[0]->failureUsage->usage->cacheWrite1hInputTokens)->toBe(3);
});

it('preserves SDK failure telemetry and leaves a pre-byte failure usage null', function (): void {
    config()->set('prism.providers.anthropic.api_key', 'owned-fake');
    config()->set('prism.telemetry.enabled', true);
    Http::preventStrayRequests();
    $seen = [];
    app('events')->listen(GenerationFailed::class, function ($e) use (&$seen): void {
        $seen[] = $e;
    });
    Http::fake(['*' => Http::response(failedUsageStart('Anthropic')."data: {broken\n\n", 200)]);
    expect(fn (): array => iterator_to_array(Prism::text()->using('anthropic', 'owned')->withPrompt('owned')->asStream()))->toThrow(PrismStreamDecodeException::class);
    expect($seen)->toHaveCount(1)->and($seen[0]->failureUsage->usage->promptTokens)->toBe(11);
    Http::fake(['*' => Http::failedConnection('owned pre-byte')]);
    expect(fn (): array => iterator_to_array(Prism::text()->using('anthropic', 'owned')->withPrompt('owned')->asStream()))->toThrow(ConnectionException::class);
    expect($seen)->toHaveCount(2)->and($seen[1]->failureUsage)->toBeNull();
});

it('isolates interleaved native attempts and resets the next request', function (): void {
    Http::preventStrayRequests();
    $request = new Request(model: 'owned', messages: [new UserMessage('owned')], maxSteps: 5);
    $body = failedUsageStart('Anthropic').failedUsageSse(['type' => 'error', 'error' => ['type' => 'owned', 'message' => 'owned']]);
    $first = (new ReflectionMethod($a = failedUsageHandler('Anthropic'), 'processStream'))->invoke($a, new Response(new PsrResponse(200, [], $body)), $request, 2);
    $secondBody = str_replace('"input_tokens":11', '"input_tokens":41', $body);
    $second = (new ReflectionMethod($b = failedUsageHandler('Anthropic'), 'processStream'))->invoke($b, new Response(new PsrResponse(200, [], $secondBody)), $request, 3);
    $first->rewind();
    $second->rewind();
    $one = iterator_to_array($first);
    $two = iterator_to_array($second);
    $oneError = array_values(array_filter($one, fn ($e): bool => $e instanceof ErrorEvent))[0];
    $twoError = array_values(array_filter($two, fn ($e): bool => $e instanceof ErrorEvent))[0];
    expect($oneError->failureUsage->usage->promptTokens)->toBe(11)
        ->and($twoError->failureUsage->usage->promptTokens)->toBe(41)
        ->and($oneError->failureUsage->stepIndex)->toBe(2)
        ->and($twoError->failureUsage->stepIndex)->toBe(3);
    $none = iterator_to_array((new ReflectionMethod($a, 'processStream'))->invoke($a, new Response(new PsrResponse(200, [], failedUsageStart('Anthropic', false).failedUsageSse(['type' => 'error', 'error' => ['type' => 'owned', 'message' => 'owned']]))), $request));
    expect(array_values(array_filter($none, fn ($e): bool => $e instanceof ErrorEvent))[0]->failureUsage)->toBeNull();
});

it('preserves a user exception thrown into a suspended generator without adding a read error', function (): void {
    $handler = failedUsageHandler('Anthropic');
    $generator = (new ReflectionMethod($handler, 'processStream'))->invoke($handler, new Response(new PsrResponse(200, [], failedUsageStart('Anthropic'))), new Request(model: 'owned'));
    $generator->rewind();
    $user = new LogicException('owned user failure');
    try {
        $generator->throw($user);
        test()->fail('Expected original user failure');
    } catch (LogicException $e) {
        expect($e)->toBe($user);
    }
});

it('retains failed Replicate polling metrics without successful completion telemetry', function (): void {
    Http::preventStrayRequests();
    Http::fakeSequence()->push(['id' => 'owned', 'status' => 'processing', 'metrics' => ['input_token_count' => 11, 'output_token_count' => 3]])
        ->push(['id' => 'owned', 'status' => 'failed', 'error' => 'private diagnostic', 'output' => ['partial'], 'metrics' => ['input_token_count' => 11, 'output_token_count' => 4]]);
    $handler = new Stream(Http::baseUrl('https://offline.invalid'), true, 0, 1);
    $events = iterator_to_array($handler->handle(new Request(model: 'owned/model:'.str_repeat('a', 64), messages: [new UserMessage('owned')])));
    $error = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof ErrorEvent))[0];
    expect($error->failureUsage->usage->promptTokens)->toBe(11)
        ->and($error->failureUsage->usage->completionTokens)->toBe(4)
        ->and(json_encode($error->toArray()))->not->toContain('private diagnostic');
    expect(array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEndEvent))[0]->finishReason)->toBe(FinishReason::Error);
});

it('keeps failure usage through native data and broadcast adapters and omits legacy null', function (): void {
    $adapter = new DataProtocolAdapter;
    $legacy = new ErrorEvent('id', 1, 'owned', 'owned', false);
    $convert = new ReflectionMethod($adapter, 'handleError');
    expect($convert->invoke($adapter, $legacy))->toBe(['type' => 'error', 'errorText' => 'owned']);
    $payload = new StreamFailureUsage(new Usage(11, 3), 0, ['prompt_tokens', 'completion_tokens']);
    $event = new ErrorEvent('id', 1, 'owned', 'owned', false, failureUsage: $payload);
    expect($convert->invoke($adapter, $event)['failure_usage'])->toBe($payload->toArray());
    $broadcast = new ErrorBroadcast($event, [new Channel('owned')]);
    expect($broadcast->broadcastWith()['failure_usage'])->toBe($payload->toArray());
});

it('does not duplicate the final foreign error or leak its private diagnostic through the data adapter', function (): void {
    $payload = new StreamFailureUsage(new Usage(11, 3), 0, ['prompt_tokens', 'completion_tokens']);
    $generator = (function () use ($payload): Generator {
        yield new ErrorEvent('id', 1, 'stream_read_failed', 'The provider stream could not be read.', false, failureUsage: $payload);
        throw new RuntimeException('private transport diagnostic');
    })();
    $response = (new DataProtocolAdapter)($generator);
    $output = '';
    ob_start(function (string $chunk) use (&$output): string {
        $output .= $chunk;

        return '';
    });
    try {
        $response->sendContent();
    } finally {
        ob_end_flush();
    }
    expect(substr_count($output, '"type":"error"'))->toBe(1)
        ->and($output)->toContain('"failure_usage"', '"prompt_tokens":11')
        ->not->toContain('private transport diagnostic');
});

it('normalizes provider cache reasoning and cost reports without changing success accounting', function (string $provider, array $data, array $expected): void {
    $tracker = new StreamFailureUsageTracker($provider);
    $tracker->record($data);
    $usage = $tracker->snapshot()->usage->toArray();
    foreach ($expected as $field => $value) {
        expect($usage[$field])->toBe($value);
    }
})->with([
    ['deepseek', ['usage' => ['prompt_tokens' => 11, 'completion_tokens' => 3, 'prompt_cache_hit_tokens' => 7]], ['prompt_tokens' => 4, 'cache_read_input_tokens' => 7]],
    ['openai', ['response' => ['usage' => ['input_tokens' => 11, 'output_tokens' => 3, 'input_tokens_details' => ['cached_tokens' => 4, 'cache_write_tokens' => 2]]]], ['prompt_tokens' => 5, 'cache_write_input_tokens' => 2, 'cache_read_input_tokens' => 4]],
    ['gemini', ['usageMetadata' => ['promptTokenCount' => 11, 'candidatesTokenCount' => 3, 'cachedContentTokenCount' => 4, 'thoughtsTokenCount' => 5, 'toolUsePromptTokenCount' => 2]], ['prompt_tokens' => 9, 'completion_tokens' => 8, 'thought_tokens' => 5]],
    ['xai', ['usage' => ['prompt_tokens' => 11, 'completion_tokens' => 3, 'completion_tokens_details' => ['reasoning_tokens' => 5]]], ['prompt_tokens' => 11, 'completion_tokens' => 8]],
    ['openrouter', ['usage' => ['prompt_tokens' => 11, 'completion_tokens' => 3, 'cost' => 0.1, 'is_byok' => true, 'cost_details' => ['upstream_inference_cost' => 0.2]]], ['prompt_tokens' => 11, 'cost' => 0.30000000000000004]],
]);

it('preserves native public RequestException mapping and listener exception precedence', function (bool $reported, bool $listenerThrows): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'owned-fake');
    config()->set('prism.telemetry.enabled', true);
    $sentinel = new RequestException(new Response(new PsrResponse(503, [], 'private transport response')));
    $stream = new class(Utils::streamFor(failedUsageStart('Anthropic', $reported)), $sentinel) implements StreamInterface
    {
        use StreamDecoratorTrait;

        public function __construct(StreamInterface $stream, private readonly Throwable $failure)
        {
            $this->stream = $stream;
        }

        public function eof(): bool
        {
            return false;
        }

        public function read($length): string
        {
            if ($this->stream->eof()) {
                throw $this->failure;
            }

            return $this->stream->read($length);
        }
    };
    Http::fake(['*' => Http::response($stream, 200)]);
    $telemetry = [];
    app('events')->listen(GenerationErrored::class, function ($event) use (&$telemetry): void {
        $telemetry[] = $event;
    });
    $listenerFailure = new LogicException('owned failed listener failure');
    if ($listenerThrows) {
        app('events')->listen(GenerationFailed::class, function () use ($listenerFailure): void {
            throw $listenerFailure;
        });
    }
    $events = [];
    $caught = null;
    try {
        foreach (Prism::text()->using('anthropic', 'owned')->withPrompt('owned')->asStream() as $event) {
            $events[] = $event;
        }
    } catch (Throwable $exception) {
        $caught = $exception;
    }
    $errors = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof ErrorEvent));
    if ($listenerThrows) {
        expect($caught)->toBe($listenerFailure)
            ->and($errors)->toHaveCount($reported ? 1 : 0)
            ->and($telemetry)->toHaveCount($reported ? 1 : 0);

        return;
    }
    expect($caught)->toBeInstanceOf(PrismException::class)
        ->and($caught::class)->toBe(PrismException::class)
        ->and($caught->getCode())->toBe(503)
        ->and($caught->httpStatus)->toBeNull()
        ->and($caught->getPrevious())->toBe($sentinel);
    if (! $reported) {
        expect($errors)->toBeEmpty()->and($telemetry)->toBeEmpty()
            ->and($caught->streamFailureUsage())->toBeNull();

        return;
    }
    expect($errors)->toHaveCount(1)
        ->and($caught->streamFailureUsage())->toBe($errors[0]->failureUsage)
        ->and($errors[0]->failureUsage->usage->promptTokens)->toBe(11)
        ->and($telemetry)->toHaveCount(1)
        ->and($telemetry[0]->failureUsage->usage->promptTokens)->toBe(11)
        ->and(json_encode($errors[0]))->not->toContain('private transport response');
})->with([[true, false], [false, false], [true, true], [false, true]]);

it('keeps pre-byte HTTP exception class code and status unchanged with no failure usage', function (): void {
    config()->set('prism.providers.anthropic.api_key', 'owned-fake');
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => ['message' => 'owned']], 503)]);
    $events = [];
    $caught = null;
    try {
        foreach (Prism::text()->using('anthropic', 'owned')->withPrompt('owned')->asStream() as $event) {
            $events[] = $event;
        }
    } catch (Throwable $failure) {
        $caught = $failure;
    }
    expect($caught::class)->toBe(PrismException::class)
        ->and($caught->getCode())->toBe(503)
        ->and($caught->httpStatus)->toBeNull()
        ->and($caught->getPrevious())->toBeInstanceOf(RequestException::class)
        ->and($caught->streamFailureUsage())->toBeNull()
        ->and($events)->toBeEmpty();
});

it('preserves nonsequential custom provider keys through the public multi-event stream', function (bool $telemetry): void {
    config()->set('prism.telemetry.enabled', $telemetry);
    $provider = new class extends Provider
    {
        public function stream(Request $request): Generator
        {
            yield 'begin' => new StreamStartEvent('start', 0, 'owned', 'owned');
            yield 7 => new TextDeltaEvent('one', 0, 'a', 'owned');
            yield 'tail' => new TextDeltaEvent('two', 0, 'b', 'owned');
            yield 41 => new StreamEndEvent('end', 0, FinishReason::Stop);
        }
    };
    resolve('prism-manager')->extend('owned-keys', fn (): Provider => $provider);
    $events = iterator_to_array(Prism::text()->using('owned-keys', 'owned')->withPrompt('owned')->asStream());
    // These are the independently measured 0.145.1 public API keys. Native
    // telemetry itself already numbers its wrapper; the outer request must
    // preserve whichever keys that selected stream supplies.
    expect(array_keys($events))->toBe($telemetry ? [0, 1, 2, 3] : ['begin', 7, 'tail', 41])
        ->and(array_map(fn (StreamEvent $event): string => $event->id, array_values($events)))->toBe(['start', 'one', 'two', 'end']);
})->with([false, true]);

it('preserves public custom provider send and throw forwarding with telemetry disabled', function (): void {
    config()->set('prism.telemetry.enabled', false);
    $provider = new class extends Provider
    {
        public function stream(Request $request): Generator
        {
            $received = yield 'first' => new TextDeltaEvent('first', 0, 'ready', 'owned');
            yield 'received' => new TextDeltaEvent('received', 0, (string) $received, 'owned');
            try {
                yield 'throwpoint' => new TextDeltaEvent('throwpoint', 0, 'ready', 'owned');
            } catch (LogicException $failure) {
                yield 'caught' => new TextDeltaEvent('caught', 0, $failure->getMessage(), 'owned');
            }
        }
    };
    resolve('prism-manager')->extend('owned-input', fn (): Provider => $provider);
    $stream = Prism::text()->using('owned-input', 'owned')->withPrompt('owned')->asStream();
    $stream->rewind();
    $stream->send('owned-send');
    expect($stream->key())->toBe('received')->and($stream->current()->delta)->toBe('owned-send');
    $stream->next();
    $stream->throw(new LogicException('owned-throw'));
    expect($stream->key())->toBe('caught')->and($stream->current()->delta)->toBe('owned-throw');
});
