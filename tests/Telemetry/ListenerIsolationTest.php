<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationErrored;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\StepCompleted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;

/*
 * A telemetry listener observes execution; it may not change it. A listener
 * that throws on any telemetry event must not interrupt a generation or a
 * tool call: the approved tool runs exactly once, the caller gets the whole
 * result, the events keep their order, and the listener's exception is
 * reported through Laravel's exception handler rather than thrown.
 *
 * Driven through Prism::text(), Prism::structured() and Prism::embeddings()
 * over faked HTTP. No request leaves the process.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.providers.azure.url', 'https://g380.invalid');
    config()->set('prism.providers.azure.api_key', 'not-a-key');
    config()->set('prism.providers.azure.api_version', '2024-10-21');
    config()->set('prism.providers.azure.deployment_name', 'test-model');
    config()->set('prism.providers.anthropic.api_key', 'not-a-key');
    config()->set('prism.providers.openai.api_key', 'not-a-key');
});

/** @return list<class-string> */
function g380EventTypes(): array
{
    return [GenerationStarted::class, StepCompleted::class, ToolInvoked::class, GenerationCompleted::class, GenerationFailed::class, GenerationErrored::class];
}

/**
 * Record every telemetry event in order, then (optionally) throw from a second
 * listener on one event type. The recorder is registered first, so it still
 * sees an event whose later listener throws.
 *
 * @param  array<int, string>  $seen
 */
function g380Listen(array &$seen, ?string $throwOn, int &$thrown): void
{
    foreach (g380EventTypes() as $type) {
        Event::listen($type, function (object $event) use (&$seen): void {
            $seen[] = class_basename($event);
        });
    }

    if ($throwOn !== null) {
        Event::listen($throwOn, function () use (&$thrown, $throwOn): never {
            $thrown++;

            throw new LogicException('g380-listener-outage:'.class_basename($throwOn));
        });
    }
}

function g380Tool(int &$ran): \Prism\Prism\Tool
{
    return Tool::as('delete_file')->for('Delete a file')->withStringParameter('path', 'Path')
        ->using(function (string $path) use (&$ran): string {
            $ran++;

            return "deleted {$path}";
        })
        ->requiresApproval();
}

/** @return array<int, mixed> */
function g380ApprovedConversation(): array
{
    return [
        new UserMessage('Delete a.txt.'),
        new AssistantMessage(
            content: '',
            toolCalls: [new ToolCall(id: 'call_1', name: 'delete_file', arguments: ['path' => 'a.txt'])],
            additionalContent: [],
            toolApprovalRequests: [new ToolApprovalRequest(approvalId: 'approval-1', toolCallId: 'call_1')],
        ),
        new ToolResultMessage([], [new ToolApprovalResponse(approvalId: 'approval-1', approved: true)]),
    ];
}

function g380ChatCompletion(string $content): array
{
    return [
        'id' => 'chat-g380', 'object' => 'chat.completion', 'created' => 1, 'model' => 'test-model',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ];
}

function g380ChatCompletionStream(string $content): string
{
    return 'data: '.json_encode([
        'id' => 'chat-g380', 'object' => 'chat.completion.chunk', 'model' => 'test-model',
        'choices' => [['index' => 0, 'delta' => ['content' => $content], 'finish_reason' => 'stop']],
    ])."\n\n".'data: [DONE]'."\n\n";
}

function g380AnthropicMessage(string $text): array
{
    return [
        'id' => 'msg_g380', 'type' => 'message', 'role' => 'assistant', 'model' => 'test-model',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ];
}

/** @return array{ran: int, text: string, result: ?string} */
function g380ResumeText(): array
{
    Http::fake(['*' => Http::response(g380ChatCompletion('Done.'))]);
    $ran = 0;
    $response = Prism::text()->using(Provider::Azure, 'test-model')->withTools([g380Tool($ran)])->withMaxSteps(3)
        ->withMessages(g380ApprovedConversation())->asText();

    $results = $response->messages
        ->filter(fn ($message): bool => $message instanceof ToolResultMessage)
        ->flatMap(fn (ToolResultMessage $message): array => $message->toolResults);

    return ['ran' => $ran, 'text' => $response->text, 'result' => $results->first()?->result];
}

/** @return array{ran: int, text: string, result: ?string} */
function g380ResumeStructured(): array
{
    Http::fake(['*' => Http::response(g380AnthropicMessage('{"answer":"done"}'))]);
    $ran = 0;
    $response = Prism::structured()->using(Provider::Anthropic, 'test-model')
        ->withSchema(new ObjectSchema('answer', 'The answer', [new StringSchema('answer', 'The answer')], ['answer']))
        ->withTools([g380Tool($ran)])->withMaxSteps(3)
        ->withMessages(g380ApprovedConversation())->asStructured();

    return ['ran' => $ran, 'text' => (string) ($response->structured['answer'] ?? ''), 'result' => null];
}

/** @return array{ran: int, text: string, result: ?string} */
function g380ResumeStream(): array
{
    Http::fake(['*' => Http::response(g380ChatCompletionStream('Done.'), 200, ['Content-Type' => 'text/event-stream'])]);
    $ran = 0;
    $events = collect(iterator_to_array(Prism::text()->using(Provider::Azure, 'test-model')->withTools([g380Tool($ran)])->withMaxSteps(3)
        ->withMessages(g380ApprovedConversation())->asStream(), false));

    expect($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop);

    return [
        'ran' => $ran,
        'text' => $events->whereInstanceOf(TextDeltaEvent::class)->map(fn (TextDeltaEvent $event): string => $event->delta)->implode(''),
        'result' => $events->whereInstanceOf(ToolResultEvent::class)->sole()->toolResult->result,
    ];
}

/*
 * Each resumed generation, crossed with every event type it dispatches. The
 * Azure stream emits no StepCompleted, so that row is not asked of it.
 */
dataset('g380 resumed generations', function (): array {
    $runs = [
        'text' => [g380ResumeText(...), 'Done.', 'deleted a.txt', [GenerationStarted::class, ToolInvoked::class, StepCompleted::class, GenerationCompleted::class]],
        'structured' => [g380ResumeStructured(...), 'done', null, [GenerationStarted::class, ToolInvoked::class, StepCompleted::class, GenerationCompleted::class]],
        'stream' => [g380ResumeStream(...), 'Done.', 'deleted a.txt', [GenerationStarted::class, ToolInvoked::class, GenerationCompleted::class]],
    ];
    $rows = [];
    foreach ($runs as $name => [$run, $text, $result, $types]) {
        foreach ($types as $type) {
            $rows[$name.' / '.class_basename($type)] = [$run, $text, $result, $type];
        }
    }

    return $rows;
});

it('runs an approved tool once and returns the whole result when a telemetry listener throws', function (Closure $run, string $text, ?string $result, string $throwOn): void {
    // The order a healthy listener sees, taken from the same run with no failure.
    $baseline = [];
    $none = 0;
    g380Listen($baseline, null, $none);
    expect($run())->toBe(['ran' => 1, 'text' => $text, 'result' => $result]);
    expect($baseline)->toContain(class_basename($throwOn));

    foreach (g380EventTypes() as $type) {
        Event::forget($type);
    }

    Exceptions::fake();
    $seen = [];
    $thrown = 0;
    g380Listen($seen, $throwOn, $thrown);

    expect($run())->toBe(['ran' => 1, 'text' => $text, 'result' => $result])
        ->and($seen)->toBe($baseline)
        ->and($thrown)->toBe(count(array_keys($baseline, class_basename($throwOn), true)));

    Exceptions::assertReported(fn (LogicException $failure): bool => $failure->getMessage() === 'g380-listener-outage:'.class_basename($throwOn));
})->with('g380 resumed generations');

it('keeps tool spans ahead of the generation completion when a listener throws', function (): void {
    Exceptions::fake();
    $seen = [];
    $thrown = 0;
    g380Listen($seen, ToolInvoked::class, $thrown);

    expect(g380ResumeText()['ran'])->toBe(1)
        ->and($seen)->toBe(['GenerationStarted', 'ToolInvoked', 'StepCompleted', 'GenerationCompleted']);
});

it('surfaces the provider failure, not the listener failure, when a GenerationFailed listener throws', function (): void {
    Exceptions::fake();
    Http::fake(['*' => Http::response(['error' => ['message' => 'g380 upstream outage']], 500)]);
    $seen = [];
    $thrown = 0;
    g380Listen($seen, GenerationFailed::class, $thrown);
    $ran = 0;

    expect(fn (): array => iterator_to_array(Prism::text()->using(Provider::Azure, 'test-model')->withTools([g380Tool($ran)])
        ->withPrompt('Delete a.txt.')->asStream(), false))
        ->toThrow(PrismException::class);

    expect($thrown)->toBe(1)->and($seen)->toBe(['GenerationStarted', 'GenerationFailed']);
    Exceptions::assertReported(fn (LogicException $failure): bool => $failure->getMessage() === 'g380-listener-outage:GenerationFailed');
});

it('returns embeddings when a telemetry listener throws', function (string $throwOn): void {
    Exceptions::fake();
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.25, 0.5]]],
        'model' => 'text-embedding-3-small',
        'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
    ])]);
    $seen = [];
    $thrown = 0;
    g380Listen($seen, $throwOn, $thrown);

    $response = Prism::embeddings()->using(Provider::OpenAI, 'text-embedding-3-small')->fromInput('g380')->asEmbeddings();

    expect($response->embeddings[0]->embedding)->toBe([0.25, 0.5])
        ->and($thrown)->toBe(1)
        ->and($seen)->toBe(['GenerationStarted', 'GenerationCompleted']);
    Exceptions::assertReported(LogicException::class);
})->with([
    'GenerationStarted' => [GenerationStarted::class],
    'GenerationCompleted' => [GenerationCompleted::class],
]);

it('contains the failure when the exception handler itself throws while reporting it', function (): void {
    app()->instance(ExceptionHandler::class, new class implements ExceptionHandler
    {
        public function report(Throwable $e): never
        {
            throw new RuntimeException('g380-handler-outage');
        }

        public function shouldReport(Throwable $e): bool
        {
            return true;
        }

        public function render($request, Throwable $e): never
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e): void {}
    });
    $seen = [];
    $thrown = 0;
    g380Listen($seen, ToolInvoked::class, $thrown);

    expect(g380ResumeText())->toBe(['ran' => 1, 'text' => 'Done.', 'result' => 'deleted a.txt'])
        ->and($thrown)->toBe(1);
});

it('dispatches every telemetry event through the guarded helper', function (): void {
    $root = dirname(__DIR__, 2).'/src';
    $telemetry = (string) file_get_contents($root.'/Telemetry/Telemetry.php');

    // The one bare dispatch is the guarded one inside Telemetry::dispatch().
    expect(preg_match_all('/(?<![\w>:$])event\(/', $telemetry))->toBe(1);

    $names = implode('|', array_map(class_basename(...), g380EventTypes()));
    $outside = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
        if ($file->getExtension() !== 'php') {
            continue;
        }
        if (str_ends_with($path, '/Telemetry/Telemetry.php')) {
            continue;
        }
        if (preg_match('/new\s+(?:'.$names.')\s*\(/', (string) file_get_contents($path)) === 1) {
            $outside[] = $path;
        }
    }

    expect($outside)->toBe([]);
});
