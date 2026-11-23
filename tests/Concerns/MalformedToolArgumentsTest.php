<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

const MALFORMED_ARGUMENTS = '{"amount": 5';

function malformedChatStream(string $args, string $text = ''): string
{
    $line = fn (array $choice): string => 'data: '.json_encode(['id' => 'c1', 'model' => 'm', 'choices' => [$choice]])."\n\n";

    if ($text !== '') {
        return $line(['index' => 0, 'delta' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => null])
            .$line(['index' => 0, 'delta' => (object) [], 'finish_reason' => 'stop'])."data: [DONE]\n\n";
    }

    return $line(['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'probe', 'arguments' => $args]]]], 'finish_reason' => null])
        .$line(['index' => 0, 'delta' => (object) [], 'finish_reason' => 'tool_calls'])."data: [DONE]\n\n";
}

/**
 * @return array{0: string, 1: string}
 */
function malformedStreamBodies(string $provider, string $args): array
{
    $sse = fn (array $events): string => implode('', array_map(fn (array $event): string => 'data: '.json_encode($event)."\n\n", $events));
    $named = fn (array $events): string => implode('', array_map(fn (array $event): string => 'event: '.$event['type']."\ndata: ".json_encode($event)."\n\n", $events));
    $usage = ['input_tokens' => 1, 'output_tokens' => 1];
    $geminiUsage = ['promptTokenCount' => 1, 'candidatesTokenCount' => 1];
    $ollamaEnd = json_encode(['model' => 'm', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 1, 'eval_count' => 1])."\n";
    $functionCall = ['id' => 'fc_1', 'type' => 'function_call', 'call_id' => 'call_1', 'name' => 'probe', 'arguments' => $args, 'status' => 'completed'];
    $message = ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'done']]];

    return match ($provider) {
        'anthropic' => [
            $named([
                ['type' => 'message_start', 'message' => ['id' => 'm1', 'model' => 'm', 'role' => 'assistant', 'content' => [], 'usage' => $usage]],
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'probe', 'input' => (object) []]],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $args]],
                ['type' => 'content_block_stop', 'index' => 0],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 1]],
                ['type' => 'message_stop'],
            ]),
            $named([
                ['type' => 'message_start', 'message' => ['id' => 'm2', 'model' => 'm', 'role' => 'assistant', 'content' => [], 'usage' => $usage]],
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'done']],
                ['type' => 'content_block_stop', 'index' => 0],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]],
                ['type' => 'message_stop'],
            ]),
        ],
        'gemini', 'vertex' => [
            $sse([['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'probe', 'args' => $args]]]], 'finishReason' => 'STOP']], 'modelVersion' => 'm', 'responseId' => 'r1', 'usageMetadata' => $geminiUsage]]),
            $sse([['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'done']]], 'finishReason' => 'STOP']], 'modelVersion' => 'm', 'responseId' => 'r2', 'usageMetadata' => $geminiUsage]]),
        ],
        'ollama' => [
            json_encode(['model' => 'm', 'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'probe', 'arguments' => $args]]]], 'done' => false])."\n".$ollamaEnd,
            json_encode(['model' => 'm', 'message' => ['role' => 'assistant', 'content' => 'done'], 'done' => false])."\n".$ollamaEnd,
        ],
        'qwen' => [
            $sse([['request_id' => 'r1', 'output' => ['choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'probe', 'arguments' => $args]]]], 'finish_reason' => 'tool_calls']]], 'usage' => $usage]]),
            $sse([['request_id' => 'r2', 'output' => ['choices' => [['message' => ['role' => 'assistant', 'content' => 'done'], 'finish_reason' => 'stop']]], 'usage' => $usage]]),
        ],
        'openai' => [
            $sse([
                ['type' => 'response.created', 'response' => ['id' => 'r1', 'model' => 'm']],
                ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => [...$functionCall, 'arguments' => '']],
                ['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'item_id' => 'fc_1', 'delta' => $args],
                ['type' => 'response.function_call_arguments.done', 'output_index' => 0, 'item_id' => 'fc_1', 'arguments' => $args],
                ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $functionCall],
                ['type' => 'response.completed', 'response' => ['id' => 'r1', 'model' => 'm', 'status' => 'completed', 'output' => [$functionCall], 'usage' => $usage]],
            ]),
            $sse([
                ['type' => 'response.created', 'response' => ['id' => 'r2', 'model' => 'm']],
                ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => [...$message, 'content' => []]],
                ['type' => 'response.output_text.delta', 'output_index' => 0, 'item_id' => 'msg_1', 'content_index' => 0, 'delta' => 'done'],
                ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $message],
                ['type' => 'response.completed', 'response' => ['id' => 'r2', 'model' => 'm', 'status' => 'completed', 'output' => [$message], 'usage' => $usage]],
            ]),
        ],
        default => [malformedChatStream($args), malformedChatStream('', 'done')],
    };
}

/**
 * Drains a stream and records a crash, so that a turn which dies fails its
 * test on an assertion rather than erroring out of it.
 */
function guardedMalformedStream(Closure $start, ?string &$crash): Generator
{
    $crash = null;

    try {
        yield from $start();
    } catch (Throwable $failure) {
        $crash = $failure::class.': '.$failure->getMessage();
    }
}

function configureMalformedProvider(string $label): string
{
    config()->set('prism.providers.openai.api_format', $label === 'openai-chat' ? 'chat_completions' : 'responses');
    config()->set('prism.providers.azure.url', 'https://azure.test/openai');
    config()->set('prism.providers.vertex.project_id', 'p');
    config()->set('prism.providers.vertex.access_token', 'fake');

    foreach (['openai', 'anthropic', 'gemini', 'groq', 'mistral', 'openrouter', 'requesty', 'deepseek', 'qwen', 'xai', 'z', 'azure'] as $name) {
        config()->set("prism.providers.$name.api_key", 'fake');
    }

    return $label === 'openai-chat' ? 'openai' : $label;
}

function fakeMalformedStream(string $label, string $args): void
{
    [$first, $second] = malformedStreamBodies($label === 'openai-chat' ? 'chat' : $label, $args);

    Http::fake(['*' => Http::sequence()
        ->push($first, 200, ['Content-Type' => 'text/event-stream'])
        ->push($second, 200, ['Content-Type' => 'text/event-stream'])]);
}

/**
 * The tool call as the follow-up request replays it, wherever this provider
 * puts it: `{}` when replayed empty, `omitted` when the provider's shape drops
 * the field for a call with no arguments.
 */
function replayedToolArguments(mixed $node, ?string $key = null): ?string
{
    if (is_array($node)) {
        foreach ($node as $child) {
            $found = replayedToolArguments($child);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    if (! $node instanceof stdClass) {
        return null;
    }

    if ($key === 'functionCall') {
        return property_exists($node, 'args') ? (string) json_encode($node->args) : 'omitted';
    }

    if (($node->name ?? null) === 'probe') {
        foreach (['arguments', 'input'] as $field) {
            if (property_exists($node, $field)) {
                return is_string($node->{$field}) ? $node->{$field} : (string) json_encode($node->{$field});
            }
        }
    }

    foreach (get_object_vars($node) as $childKey => $child) {
        $found = replayedToolArguments($child, $childKey);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

function secondRequestBody(): string
{
    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    /** @var HttpRequest $request */
    $request = $recorded[1][0];

    return $request->body();
}

dataset('streaming providers', ['openai', 'openai-chat', 'azure', 'anthropic', 'gemini', 'vertex', 'groq', 'mistral', 'openrouter', 'requesty', 'deepseek', 'qwen', 'xai', 'z', 'ollama']);

it('reports unparseable arguments as a tool error and completes the turn', function (string $label): void {
    $provider = configureMalformedProvider($label);
    fakeMalformedStream($label, MALFORMED_ARGUMENTS);

    $approvalChecks = 0;
    $handlerRuns = 0;
    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount', false)
        ->requiresApproval(function (array $args) use (&$approvalChecks): bool {
            $approvalChecks++;

            return false;
        })
        ->using(function (string ...$args) use (&$handlerRuns): string {
            $handlerRuns++;

            return 'ran';
        });

    $results = [];
    $end = null;
    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asStream(), $crash) as $event) {
        if ($event instanceof ToolResultEvent) {
            $results[] = $event;
        }
        if ($event instanceof StreamEndEvent) {
            $end = $event;
        }
    }

    expect($crash)->toBeNull();

    $body = secondRequestBody();

    expect($approvalChecks)->toBe(0)
        ->and($handlerRuns)->toBe(0)
        ->and($results)->toHaveCount(1)
        ->and($results[0]->success)->toBeFalse()
        ->and($results[0]->toolResult->result)->toContain('not valid JSON')
        ->and($results[0]->toolResult->args)->toBe([])
        ->and($end)->toBeInstanceOf(StreamEndEvent::class)
        ->and($end->finishReason)->toBe(FinishReason::Stop)
        ->and($body)->toContain('not valid JSON')
        ->and(replayedToolArguments(json_decode($body, false)))->toBeIn(['{}', 'omitted']);
})->with('streaming providers');

it('treats arguments that are not a named map as unusable', function (string $label, string $args): void {
    $provider = configureMalformedProvider($label);
    fakeMalformedStream($label, $args);

    $handlerRuns = 0;
    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount', false)
        ->using(function (mixed ...$received) use (&$handlerRuns): string {
            $handlerRuns++;

            return 'ran';
        });

    $results = [];
    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asStream(), $crash) as $event) {
        if ($event instanceof ToolResultEvent) {
            $results[] = $event;
        }
    }

    expect($crash)->toBeNull();

    expect($handlerRuns)->toBe(0)
        ->and($results)->toHaveCount(1)
        ->and($results[0]->success)->toBeFalse()
        ->and(replayedToolArguments(json_decode(secondRequestBody(), false)))->toBeIn(['{}', 'omitted']);
})->with(['openai-chat', 'azure', 'anthropic', 'openrouter'])->with(['a number' => '5', 'a string' => '"x"', 'a list' => '[1,2]', 'a numeric key' => '{"0":"x"}', 'true' => 'true']);

it('does not ask for approval or evaluate a throwing check on unparseable arguments', function (bool|Closure $condition): void {
    $provider = configureMalformedProvider('groq');
    fakeMalformedStream('groq', MALFORMED_ARGUMENTS);

    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount', false)
        ->requiresApproval($condition)
        ->using(fn (string ...$args): string => 'ran');

    $approvalRequests = 0;
    $failed = 0;
    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asStream(), $crash) as $event) {
        $approvalRequests += $event instanceof ToolApprovalRequestEvent ? 1 : 0;
        $failed += $event instanceof ToolResultEvent && ! $event->success ? 1 : 0;
    }

    expect($crash)->toBeNull();

    expect($approvalRequests)->toBe(0)->and($failed)->toBe(1);
})->with([
    'always required' => [true],
    'throwing check' => [fn (): Closure => function (array $args): bool {
        throw new RuntimeException('check failed');
    }],
    'permissive check' => [fn (): Closure => fn (array $args): bool => false],
]);

it('reports an unparseable call to a client-executed tool instead of handing it to the client', function (): void {
    $provider = configureMalformedProvider('groq');
    fakeMalformedStream('groq', MALFORMED_ARGUMENTS);

    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount', false)->clientExecuted();

    $results = [];
    $end = null;
    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asStream(), $crash) as $event) {
        if ($event instanceof ToolResultEvent) {
            $results[] = $event;
        }
        if ($event instanceof StreamEndEvent) {
            $end = $event;
        }
    }

    expect($crash)->toBeNull();

    expect($results)->toHaveCount(1)
        ->and($results[0]->success)->toBeFalse()
        ->and($end->finishReason)->toBe(FinishReason::Stop);
});

it('still withholds the other calls when the withholding call is unparseable', function (): void {
    $provider = configureMalformedProvider('groq');

    $line = fn (array $choice): string => 'data: '.json_encode(['id' => 'c1', 'model' => 'm', 'choices' => [$choice]])."\n\n";
    $first = $line(['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [
        ['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'probe', 'arguments' => MALFORMED_ARGUMENTS]],
        ['index' => 1, 'id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'sibling', 'arguments' => '{}']],
    ]], 'finish_reason' => null])
        .$line(['index' => 0, 'delta' => (object) [], 'finish_reason' => 'tool_calls'])."data: [DONE]\n\n";

    Http::fake(['*' => Http::sequence()
        ->push($first, 200, ['Content-Type' => 'text/event-stream'])
        ->push(malformedChatStream('', 'done'), 200, ['Content-Type' => 'text/event-stream'])]);

    $siblingRuns = 0;
    $tools = [
        (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount', false)->clientExecuted()->withholdOtherCalls(),
        (new Tool)->as('sibling')->for('sibling')->using(function () use (&$siblingRuns): string {
            $siblingRuns++;

            return 'ran';
        }),
    ];

    $results = [];
    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withPrompt('p')->withTools($tools)->withMaxSteps(3)->asStream(), $crash) as $event) {
        if ($event instanceof ToolResultEvent) {
            $results[$event->toolResult->toolCallId] = $event->success;
        }
    }

    expect($crash)->toBeNull();

    expect($siblingRuns)->toBe(0)
        ->and($results)->toHaveCount(2)
        ->and($results)->not->toContain(true);
});

it('marks the call event so a consumer cannot read the empty object as real arguments', function (): void {
    $provider = configureMalformedProvider('azure');
    fakeMalformedStream('azure', MALFORMED_ARGUMENTS);

    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount', false)->using(fn (): string => 'ran');

    $call = null;
    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asStream(), $crash) as $event) {
        if ($event instanceof ToolCallEvent) {
            $call = $event->toArray();
        }
    }

    expect($crash)->toBeNull();

    expect($call['arguments_malformed'] ?? null)->toBeTrue()
        ->and(json_encode($call['arguments']))->toBe('{}');
});

it('runs a well-formed call exactly as before', function (string $label): void {
    $provider = configureMalformedProvider($label);
    fakeMalformedStream($label, '{"amount":"5"}');

    $checked = null;
    $received = null;
    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount')
        ->requiresApproval(function (array $args) use (&$checked): bool {
            $checked = $args;

            return false;
        })
        ->using(function (string $amount) use (&$received): string {
            $received = $amount;

            return 'ran';
        });

    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asStream(), $crash) as $event) {
        // Drain.
    }

    expect($crash)->toBeNull();

    expect($checked)->toBe(['amount' => '5'])->and($received)->toBe('5');
})->with('streaming providers');

it('completes a non-streaming turn on unparseable arguments', function (string $label): void {
    $provider = configureMalformedProvider($label);

    $message = ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'probe', 'arguments' => MALFORMED_ARGUMENTS]]]];
    $usage = ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2];
    $chat = fn (array $message, string $finish): array => ['id' => 'c1', 'model' => 'm', 'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]], 'usage' => $usage];
    $qwen = fn (array $message, string $finish): array => ['request_id' => 'r1', 'output' => ['choices' => [['message' => $message, 'finish_reason' => $finish]]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
    $shape = $label === 'qwen' ? $qwen : $chat;

    Http::fake(['*' => Http::sequence()
        ->push($shape($message, 'tool_calls'))
        ->push($shape(['role' => 'assistant', 'content' => 'done'], 'stop'))]);

    $handlerRuns = 0;
    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount', false)
        ->using(function (string ...$args) use (&$handlerRuns): string {
            $handlerRuns++;

            return 'ran';
        });

    $response = null;
    $crash = null;

    try {
        $response = Prism::text()->using($provider, 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asText();
    } catch (Throwable $failure) {
        $crash = $failure::class.': '.$failure->getMessage();
    }

    expect($crash)->toBeNull()
        ->and($handlerRuns)->toBe(0)
        ->and($response?->text)->toBe('done')
        ->and(secondRequestBody())->toContain('not valid JSON');
})->with(['openai-chat', 'azure', 'groq', 'mistral', 'openrouter', 'requesty', 'deepseek', 'qwen', 'xai', 'z']);

it('still refuses to send an unparseable call that nothing has answered', function (string $label): void {
    $provider = configureMalformedProvider($label);
    Http::fake(['*' => Http::response(malformedChatStream('', 'done'))]);

    $history = [
        new UserMessage('p'),
        new AssistantMessage('', [new ToolCall('call_1', 'probe', MALFORMED_ARGUMENTS, 'call_1')]),
    ];

    $failure = null;

    try {
        foreach (Prism::text()->using($provider, 'm')->withMessages($history)->asStream() as $event) {
            // Drain.
        }
    } catch (PrismException $caught) {
        $failure = $caught->getMessage();
    }

    expect((string) $failure)->toContain('not valid JSON');
    expect(Http::recorded())->toHaveCount(0);
})->with(['openai', 'openai-chat', 'azure', 'anthropic', 'gemini', 'groq', 'mistral', 'deepseek', 'xai', 'z', 'ollama']);

it('can resend a stored conversation holding an answered unparseable call', function (string $label): void {
    $provider = configureMalformedProvider($label);
    [, $second] = malformedStreamBodies($label === 'openai-chat' ? 'chat' : $label, MALFORMED_ARGUMENTS);
    Http::fake(['*' => Http::response($second, 200, ['Content-Type' => 'text/event-stream'])]);

    $history = [
        new UserMessage('p'),
        new AssistantMessage('', [new ToolCall('call_1', 'probe', MALFORMED_ARGUMENTS, 'call_1')]),
        new ToolResultMessage([new ToolResult('call_1', 'probe', [], 'Tool call arguments for tool probe are not valid JSON', 'call_1')]),
        new UserMessage('again'),
    ];

    $crash = null;
    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withMessages($history)->asStream(), $crash) as $event) {
        // Drain.
    }

    expect($crash)->toBeNull();

    $body = Http::recorded()[0][0]->body();

    expect(replayedToolArguments(json_decode((string) $body, false)))->toBeIn(['{}', 'omitted'])
        ->and($history[1]->toolCalls[0]->arguments)->toBe(MALFORMED_ARGUMENTS);
})->with('streaming providers');

/**
 * @return array{results: array<int, ToolResultEvent>, runs: int, crash: ?string, body: string}
 */
function resumeStoredApproval(Tool $tool, array $arguments, bool $approved, int &$runs): array
{
    $provider = configureMalformedProvider('groq');
    Http::fake(['*' => Http::response(malformedChatStream('', 'done'), 200, ['Content-Type' => 'text/event-stream'])]);

    $history = [
        new UserMessage('p'),
        new AssistantMessage('', [new ToolCall('call_1', 'probe', $arguments)], [], [new ToolApprovalRequest('apr_1', 'call_1')]),
        new ToolResultMessage([], [new ToolApprovalResponse('apr_1', $approved)]),
    ];

    $results = [];
    $crash = null;
    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withMessages($history)->withTools([$tool])->withMaxSteps(3)->asStream(), $crash) as $event) {
        if ($event instanceof ToolResultEvent) {
            $results[] = $event;
        }
    }

    return ['results' => $results, 'runs' => $runs, 'crash' => $crash, 'body' => Http::recorded()[0][0]->body()];
}

it('refuses a stored approved call in the old wrapped form', function (string $key): void {
    $runs = 0;
    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount', false)
        ->requiresApproval()
        ->using(function (string ...$args) use (&$runs): string {
            $runs++;

            return 'ran';
        });

    $outcome = resumeStoredApproval($tool, [$key => MALFORMED_ARGUMENTS], true, $runs);

    expect($outcome['crash'])->toBeNull()
        ->and($runs)->toBe(0)
        ->and($outcome['results'])->toHaveCount(1)
        ->and($outcome['results'][0]->success)->toBeFalse()
        ->and($outcome['results'][0]->toolResult->result)->toContain('cannot be used')
        ->and($outcome['results'][0]->toolResult->args)->toBe([]);
})->with(['raw', 'input']);

it('still runs an approved call when the tool really declares that parameter', function (string $key): void {
    $runs = 0;
    $received = null;
    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter($key, 'value')
        ->requiresApproval()
        ->using(function (string ...$args) use (&$runs, &$received): string {
            $runs++;
            $received = $args;

            return 'ran';
        });

    $outcome = resumeStoredApproval($tool, [$key => 'hello'], true, $runs);

    expect($outcome['crash'])->toBeNull()
        ->and($runs)->toBe(1)
        ->and($received)->toBe([$key => 'hello']);
})->with(['raw', 'input']);

it('still runs an approved call whose arguments only resemble the old form', function (array $arguments): void {
    $runs = 0;
    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount', false)
        ->withStringParameter('other', 'other', false)
        ->requiresApproval()
        ->using(function (string ...$args) use (&$runs): string {
            $runs++;

            return 'ran';
        });

    $outcome = resumeStoredApproval($tool, $arguments, true, $runs);

    expect($outcome['crash'])->toBeNull()->and($runs)->toBe(1);
})->with([
    'a declared key' => [['amount' => '5']],
    'no arguments' => [[]],
    'two keys' => [['amount' => '5', 'other' => 'x']],
]);

it('still runs an approved call when the handler names that parameter', function (string $key): void {
    $runs = 0;
    $handler = $key === 'raw'
        ? function (string $raw) use (&$runs): string {
            $runs++;

            return 'ran';
        }
    : function (string $input) use (&$runs): string {
        $runs++;

        return 'ran';
    };
    $tool = (new Tool)->as('probe')->for('probe')->requiresApproval()->using($handler);

    $outcome = resumeStoredApproval($tool, [$key => 'hello'], true, $runs);

    expect($outcome['crash'])->toBeNull()->and($runs)->toBe(1);
})->with(['raw', 'input']);

dataset('valid non-objects and unusable maps', [
    'a number' => ['5'],
    'zero as a float' => ['0.0'],
    'zero with a space' => [' 0'],
    'a quoted zero' => ['"0"'],
    'a string' => ['"x"'],
    'true' => ['true'],
    'false' => ['false'],
    'a list' => ['[1,2]'],
    'an integer key' => ['{"0":"x"}'],
    'a negative integer key' => ['{"-5":"x"}'],
]);

dataset('no arguments', [
    'an empty string' => [''],
    'the text 0' => ['0'],
    'null' => ['null'],
    'an empty list' => ['[]'],
    'an empty object' => ['{}'],
]);

it('refuses unusable arguments from arguments() for a host running its own loop', function (string $arguments): void {
    $provider = configureMalformedProvider('groq');
    fakeMalformedStream('groq', $arguments);

    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount', false)->using(fn (): string => 'ran');

    $seen = [];
    $crash = null;
    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asStream(), $crash) as $event) {
        if ($event instanceof ToolCallEvent) {
            try {
                $seen[] = ['returned' => $event->toolCall->arguments()];
            } catch (PrismException $failure) {
                $seen[] = ['refused' => $failure->getMessage()];
            }
        }
    }

    expect($crash)->toBeNull()
        ->and($seen)->toHaveCount(1)
        ->and($seen[0])->toHaveKey('refused')
        ->and($seen[0]['refused'] ?? '')->toContain('Tool call arguments for tool probe');
})->with('valid non-objects and unusable maps');

it('reads the empty forms as no arguments', function (string $arguments): void {
    $call = new ToolCall('c', 'probe', $arguments);

    expect($call->arguments())->toBe([])
        ->and($call->hasArguments())->toBeFalse()
        ->and($call->hasMalformedArguments())->toBeFalse()
        ->and($call->argumentsAsJson())->toBe('{}');
})->with('no arguments');

it('refuses an unanswered unusable call at mapping on every provider', function (string $label, string $arguments): void {
    $provider = configureMalformedProvider($label);
    Http::fake(['*' => Http::response(malformedChatStream('', 'done'))]);

    $history = [
        new UserMessage('p'),
        new AssistantMessage('', [new ToolCall('call_1', 'probe', $arguments, 'call_1')]),
    ];

    $failure = null;

    try {
        foreach (Prism::text()->using($provider, 'm')->withMessages($history)->asStream() as $event) {
            // Drain.
        }
    } catch (Throwable $caught) {
        $failure = $caught->getMessage();
    }

    expect((string) $failure)->toContain('Tool call arguments for tool probe')
        ->and(Http::recorded())->toHaveCount(0);
})->with(['openai', 'openai-chat', 'azure', 'anthropic', 'gemini', 'vertex', 'groq', 'mistral', 'openrouter', 'requesty', 'deepseek', 'qwen', 'xai', 'z', 'ollama'])
    ->with(['a number' => ['5'], 'a list' => ['[1,2]'], 'an integer key' => ['{"0":"x"}']]);

it('can resend an answered non-object call on every provider', function (string $label, string $arguments): void {
    $provider = configureMalformedProvider($label);
    [, $second] = malformedStreamBodies($label === 'openai-chat' ? 'chat' : $label, MALFORMED_ARGUMENTS);
    Http::fake(['*' => Http::response($second, 200, ['Content-Type' => 'text/event-stream'])]);

    $history = [
        new UserMessage('p'),
        new AssistantMessage('', [new ToolCall('call_1', 'probe', $arguments, 'call_1')]),
        new ToolResultMessage([new ToolResult('call_1', 'probe', [], 'failed', 'call_1')]),
        new UserMessage('again'),
    ];

    $crash = null;
    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withMessages($history)->asStream(), $crash) as $event) {
        // Drain.
    }

    expect($crash)->toBeNull()
        ->and(replayedToolArguments(json_decode((string) Http::recorded()[0][0]->body(), false)))->toBeIn(['{}', 'omitted']);
})->with('streaming providers')->with(['a number' => ['5'], 'a list' => ['[1,2]']]);

it('keeps the raw text out of captured telemetry', function (): void {
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', true);
    $provider = configureMalformedProvider('groq');
    fakeMalformedStream('groq', '{"secret-text": 5');
    Event::fake([ToolInvoked::class]);

    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount', false)->using(fn (): string => 'ran');

    $crash = null;
    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asStream(), $crash) as $event) {
        // Drain.
    }

    expect($crash)->toBeNull();

    $invoked = Event::dispatched(ToolInvoked::class)->flatten(1);

    expect($invoked)->toHaveCount(1)
        ->and(json_encode($invoked->first()->toolCall?->toArray()))->not->toContain('secret-text');
});

/**
 * @return array<string, array{0: string}>
 */
function argumentsNoRequestCanCarry(): array
{
    return [
        'a number past the float range' => ['{"amount":1e999}'],
        'a nested negative one' => ['{"a":{"b":[-1e999]}}'],
        'an integer past the float range' => ['{"amount":1'.str_repeat('0', 400).'}'],
        'a key starting with NUL' => ['{"amount":"5","\u0000a":1}'],
        'a nested key starting with NUL' => ['{"a":[{"\u0000b":1}]}'],
        'a control character inside a number' => ["{\"amount\":1\x0100}"],
        'a control character inside a literal' => ["nu\x01ll"],
        'a control character between tokens' => ["{\"amount\"\x01:1}"],
        'a control character after a backslash' => ["{\"a\":\"b\\\x01\"}"],
    ];
}

dataset('arguments no request can carry', argumentsNoRequestCanCarry());

it('refuses arguments a follow-up request could not carry', function (string $label, string $args): void {
    Http::preventStrayRequests();
    $provider = configureMalformedProvider($label);
    fakeMalformedStream($label, $args);

    $checks = 0;
    $runs = 0;
    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount', false)
        ->requiresApproval(function (array $received) use (&$checks): bool {
            $checks++;

            return (int) ($received['amount'] ?? 0) > 100;
        })
        ->using(function (mixed ...$received) use (&$runs): string {
            $runs++;

            return 'ran';
        });

    $results = [];
    $eventRecords = [];
    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asStream(), $crash) as $event) {
        if ($event instanceof ToolCallEvent) {
            $eventRecords[] = $event->toArray();
        }
        if ($event instanceof ToolResultEvent) {
            $results[] = $event;
        }
    }

    expect($crash)->toBeNull()
        ->and($checks)->toBe(0)
        ->and($runs)->toBe(0)
        ->and($results)->toHaveCount(1)
        ->and($results[0]->success)->toBeFalse()
        ->and($eventRecords)->toHaveCount(1)
        ->and($eventRecords[0]['arguments_malformed'] ?? false)->toBeTrue()
        ->and(replayedToolArguments(json_decode(secondRequestBody(), false)))->toBeIn(['{}', 'omitted']);
})->with('streaming providers')->with('arguments no request can carry');

it('keeps a control character inside a string as the value the model sent', function (): void {
    $call = new ToolCall('c', 'probe', "{\"note\":\"a\x01b\nc\",\"n\":1}");

    expect($call->hasMalformedArguments())->toBeFalse()
        ->and($call->arguments())->toBe(['note' => "a\x01b\nc", 'n' => 1])
        ->and(json_decode($call->argumentsAsJson(), true))->toBe(['note' => "a\x01b\nc", 'n' => 1]);
});

it('accepts a long digit key and a NUL that does not start a key', function (): void {
    $key = str_repeat('7', 5000);
    $call = new ToolCall('c', 'probe', '{"'.$key.'":1,"a\u0000b":2}');

    expect($call->hasMalformedArguments())->toBeFalse()
        ->and(array_keys($call->arguments()))->toBe([$key, "a\0b"])
        ->and(json_decode($call->argumentsAsJson(), true))->toBe([$key => 1, "a\0b" => 2]);
});

it('refuses a decoded map holding a value no request can carry', function (array $arguments): void {
    $call = new ToolCall('c', 'probe', $arguments);

    expect($call->hasMalformedArguments())->toBeTrue()
        ->and($call->withoutMalformedArguments()->argumentsAsJson())->toBe('{}');
})->with([
    'infinity' => [['a' => INF]],
    'not a number' => [['a' => ['b' => NAN]]],
    'a NUL key' => [["\0a" => 1]],
    'a nested NUL key' => [['a' => ["\0b" => 1]]],
]);

it('reads a non-string provider value as refused, and an absent one as none', function (mixed $value, bool $refused): void {
    $call = new ToolCall('c', 'probe', $value);

    expect($call->hasMalformedArguments())->toBe($refused);

    if (! $refused) {
        expect($call->arguments())->toBe([]);
    }
})->with([
    'a number' => [5, true],
    'zero' => [0, true],
    'a float zero' => [0.0, true],
    'true' => [true, true],
    'false' => [false, true],
    'a list' => [[1, 2], true],
    'an integer-keyed map' => [['0' => 'x'], true],
    'null' => [null, false],
    'an empty list' => [[], false],
]);

it('answers a call whose arguments arrive as a non-string value', function (string $provider, mixed $value): void {
    Http::preventStrayRequests();
    configureMalformedProvider($provider);

    $usage = ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2];
    [$first, $second] = match ($provider) {
        'openai' => [
            ['id' => 'r1', 'status' => 'completed', 'model' => 'm', 'output' => [['id' => 'fc_1', 'type' => 'function_call', 'status' => 'completed', 'call_id' => 'call_1', 'name' => 'probe', 'arguments' => $value]], 'usage' => $usage],
            ['id' => 'r2', 'status' => 'completed', 'model' => 'm', 'output' => [['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'done']]]], 'usage' => $usage],
        ],
        'anthropic' => [
            ['id' => 'm1', 'type' => 'message', 'role' => 'assistant', 'model' => 'm', 'content' => [['type' => 'tool_use', 'id' => 'call_1', 'name' => 'probe', 'input' => $value]], 'stop_reason' => 'tool_use', 'usage' => $usage],
            ['id' => 'm2', 'type' => 'message', 'role' => 'assistant', 'model' => 'm', 'content' => [['type' => 'text', 'text' => 'done']], 'stop_reason' => 'end_turn', 'usage' => $usage],
        ],
        'gemini' => [
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'probe', 'args' => $value]]]], 'finishReason' => 'STOP']], 'modelVersion' => 'm', 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1]],
            ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'done']]], 'finishReason' => 'STOP']], 'modelVersion' => 'm', 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1]],
        ],
    };
    Http::fake(['*' => Http::sequence()->push($first)->push($second)]);

    $runs = 0;
    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount', false)
        ->using(function (mixed ...$received) use (&$runs): string {
            $runs++;

            return 'ran';
        });

    $failure = null;
    $response = null;

    try {
        $response = Prism::text()->using($provider, 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asText();
    } catch (Throwable $caught) {
        $failure = $caught::class.': '.$caught->getMessage();
    }

    expect($failure)->toBeNull()
        ->and($runs)->toBe(0)
        ->and($response?->text)->toBe('done')
        ->and(Http::recorded())->toHaveCount(2);
})->with(['openai', 'anthropic', 'gemini'])->with(['a number' => [5], 'true' => [true], 'a list' => [[1, 2]], 'an integer-keyed map' => [['0' => 'x']]]);

it('refuses a non-string delta wherever it lands in a streamed call', function (string $label, array $deltas): void {
    Http::preventStrayRequests();
    $provider = configureMalformedProvider($label);

    $line = fn (array $choice): string => 'data: '.json_encode(['id' => 'c1', 'model' => 'm', 'choices' => [$choice]])."\n\n";
    $qwenLine = fn (array $toolCall, ?string $finish): string => 'data: '.json_encode(['request_id' => 'r1', 'output' => ['choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [$toolCall]], 'finish_reason' => $finish]]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])."\n\n";
    $body = '';
    foreach ($deltas as $position => $delta) {
        $toolCall = ['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'probe', 'arguments' => $delta]];
        $body .= $label === 'qwen'
            ? $qwenLine($toolCall, $position === count($deltas) - 1 ? 'tool_calls' : null)
            : $line(['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [$toolCall]], 'finish_reason' => null]);
    }
    if ($label !== 'qwen') {
        $body .= $line(['index' => 0, 'delta' => (object) [], 'finish_reason' => 'tool_calls'])."data: [DONE]\n\n";
    }
    [, $second] = malformedStreamBodies($label === 'openai-chat' ? 'chat' : $label, MALFORMED_ARGUMENTS);

    Http::fake(['*' => Http::sequence()
        ->push($body, 200, ['Content-Type' => 'text/event-stream'])
        ->push($second, 200, ['Content-Type' => 'text/event-stream'])]);

    $runs = 0;
    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount', false)
        ->using(function (mixed ...$received) use (&$runs): string {
            $runs++;

            return 'ran';
        });

    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asStream(), $crash) as $event) {
        // Drain.
    }

    expect($crash)->toBeNull()
        ->and($runs)->toBe(0)
        ->and(Http::recorded())->toHaveCount(2);
})->with(['openai-chat', 'azure', 'groq', 'mistral', 'openrouter', 'requesty', 'deepseek', 'qwen', 'xai', 'z'])->with([
    'a number' => [[5]],
    'zero' => [[0]],
    'false' => [[false]],
    'true' => [[true]],
    'a list' => [[[1, 2]]],
    'a number completing an object' => [['{"amount":', 5, '}']],
]);

it('does not keep the raw text in a call record', function (): void {
    $record = (new ToolCall('c', 'probe', '{"kept-out": 5'))->toArray();

    expect(json_encode($record))->not->toContain('kept-out')
        ->and($record['arguments_malformed'] ?? false)->toBeTrue();
});

it('resends the deepest accepted arguments on every provider', function (string $label): void {
    Http::preventStrayRequests();
    $provider = configureMalformedProvider($label);
    [, $second] = malformedStreamBodies($label === 'openai-chat' ? 'chat' : $label, MALFORMED_ARGUMENTS);
    Http::fake(['*' => Http::response($second, 200, ['Content-Type' => 'text/event-stream'])]);

    $deepest = null;
    for ($depth = 520; $depth > 0; $depth--) {
        $arguments = str_repeat('{"a":', $depth).'1'.str_repeat('}', $depth);
        if (! (new ToolCall('c', 'probe', $arguments))->hasMalformedArguments()) {
            $deepest = $arguments;
            break;
        }
    }

    $history = [
        new UserMessage('p'),
        new AssistantMessage('', [new ToolCall('call_1', 'probe', (string) $deepest, 'call_1')]),
        new ToolResultMessage([new ToolResult('call_1', 'probe', [], 'ok', 'call_1')]),
        new UserMessage('again'),
    ];

    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withMessages($history)->asStream(), $crash) as $event) {
        // Drain.
    }

    expect($crash)->toBeNull()->and(Http::recorded())->toHaveCount(1);
})->with('streaming providers');

it('checks and runs nothing for an argument key that could bind by position', function (string $label, string $args): void {
    Http::preventStrayRequests();
    $provider = configureMalformedProvider($label);
    fakeMalformedStream($label, $args);

    $checks = 0;
    $bound = [];
    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('target', 'target', false)
        ->requiresApproval(function (array $received) use (&$checks): bool {
            $checks++;

            return ($received['target'] ?? null) === 'prod';
        })
        ->using(function (string $target = 'none', mixed ...$rest) use (&$bound): string {
            $bound[] = $target;

            return 'ran';
        });

    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asStream(), $crash) as $event) {
        // Drain.
    }

    expect($crash)->toBeNull()
        ->and($checks)->toBe(0)
        ->and($bound)->toBe([])
        ->and(Http::recorded())->toHaveCount(2);
})->with(['openai', 'openai-chat', 'anthropic', 'gemini', 'mistral'])->with([
    'one integer key' => '{"0":"prod"}',
    'a one-item list' => '["prod"]',
    'an integer key beside a name' => '{"0":"prod","note":"x"}',
    'a name beside an integer key' => '{"note":"x","1":"prod"}',
    'a negative integer key' => '{"-1":"prod"}',
]);

it('never binds a numeric-looking string key to a parameter by position', function (string $args): void {
    Http::preventStrayRequests();
    $provider = configureMalformedProvider('openai-chat');
    fakeMalformedStream('openai-chat', $args);

    $bound = [];
    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('target', 'target', false)
        ->using(function (string $target = 'none', mixed ...$rest) use (&$bound): string {
            $bound[] = $target;

            return 'ran';
        });

    foreach (guardedMalformedStream(fn (): Generator => Prism::text()->using($provider, 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asStream(), $crash) as $event) {
        // Drain.
    }

    expect($crash)->toBeNull()
        ->and($bound)->not->toContain('prod')
        ->and((new ToolCall('c', 'probe', $args))->hasMalformedArguments())->toBeFalse()
        ->and(Http::recorded())->toHaveCount(2);
})->with([
    'the key 00' => '{"00":"prod"}',
    'the key 1.0' => '{"1.0":"prod"}',
    'the key -0' => '{"-0":"prod"}',
    'the key +1' => '{"+1":"prod"}',
    'a key with a leading space' => '{" 1":"prod"}',
]);

/**
 * The extra memory an action needs, as a multiple of decoding the same text
 * once. A ratio of two measurements in one process, so it does not depend on
 * the machine or on what the suite has already allocated.
 */
function memoryRelativeToDecoding(string $json, Closure $action): float
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $decoded = json_decode($json, true);
    $decoding = memory_get_peak_usage() - $before;
    unset($decoded);

    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $action();

    return (memory_get_peak_usage() - $before) / $decoding;
}

it('checks a long argument list in about the memory of reading it', function (string $json): void {
    $call = new ToolCall('call_1', 'probe', $json);
    $malformed = null;

    $ratio = memoryRelativeToDecoding($json, function () use ($call, &$malformed): void {
        $malformed = $call->hasMalformedArguments();
    });

    expect($malformed)->toBeFalse()->and($ratio)->toBeLessThan(1.6);
})->with([
    'numbers' => ['{"a":['.rtrim(str_repeat('0,', 150000), ',').']}'],
    'short lists' => ['{"a":['.rtrim(str_repeat('[0],', 50000), ',').']}'],
    'empty lists' => ['{"a":['.rtrim(str_repeat('[],', 100000), ',').']}'],
]);

it('sends a follow-up for a conversation already holding a long argument list', function (): void {
    $json = '{"a":['.rtrim(str_repeat('0,', 150000), ',').']}';
    $requests = 0;

    Http::preventStrayRequests();
    Http::fake(function () use (&$requests) {
        $requests++;

        return Http::response([
            'id' => 'r1', 'object' => 'response', 'model' => 'm', 'status' => 'completed',
            'output' => [['type' => 'message', 'id' => 'm1', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'done', 'annotations' => []]]]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
        ]);
    });

    $messages = [
        new UserMessage('go'),
        new AssistantMessage('', [new ToolCall('call_1', 'probe', $json)]),
        new ToolResultMessage([new ToolResult('call_1', 'probe', ['a' => 1], 'ran')]),
        new UserMessage('next'),
    ];
    $text = null;

    $ratio = memoryRelativeToDecoding($json, function () use ($messages, &$text): void {
        $text = Prism::text()->using('openai', 'gpt-4o')->withMessages($messages)->asText()->text;
    });

    expect($text)->toBe('done')->and($requests)->toBe(1)->and($ratio)->toBeLessThan(4.0);
});

/**
 * A provider's non-streaming reply holding one tool call, then its final
 * answer. `$arguments` is JSON placed in the reply as written, so a value the
 * decoder alone can read reaches the handler the way a real reply would.
 *
 * @return array{0: string, 1: string}
 */
function nonStreamingBodies(string $label, string $arguments): array
{
    $final = '{\"ok\":true}';

    return match ($label) {
        'anthropic' => [
            '{"id":"m1","type":"message","role":"assistant","model":"m","stop_reason":"tool_use","usage":{"input_tokens":1,"output_tokens":1},"content":[{"type":"tool_use","id":"call_1","name":"probe","input":'.$arguments.'}]}',
            '{"id":"m2","type":"message","role":"assistant","model":"m","stop_reason":"end_turn","usage":{"input_tokens":1,"output_tokens":1},"content":[{"type":"text","text":"'.$final.'"}]}',
        ],
        'gemini', 'vertex' => [
            '{"modelVersion":"m","usageMetadata":{"promptTokenCount":1,"candidatesTokenCount":1},"candidates":[{"finishReason":"STOP","content":{"role":"model","parts":[{"functionCall":{"name":"probe","args":'.$arguments.'}}]}}]}',
            '{"modelVersion":"m","usageMetadata":{"promptTokenCount":1,"candidatesTokenCount":1},"candidates":[{"finishReason":"STOP","content":{"role":"model","parts":[{"text":"'.$final.'"}]}}]}',
        ],
        'ollama' => [
            '{"model":"m","created_at":"2026-01-01T00:00:00Z","done":true,"done_reason":"stop","prompt_eval_count":1,"eval_count":1,"message":{"role":"assistant","content":"","tool_calls":[{"function":{"name":"probe","arguments":'.$arguments.'}}]}}',
            '{"model":"m","created_at":"2026-01-01T00:00:00Z","done":true,"done_reason":"stop","prompt_eval_count":1,"eval_count":1,"message":{"role":"assistant","content":"'.$final.'"}}',
        ],
        'qwen' => [
            '{"request_id":"q1","usage":{"input_tokens":1,"output_tokens":1},"output":{"choices":[{"finish_reason":"tool_calls","message":{"role":"assistant","content":"","tool_calls":[{"id":"call_1","type":"function","function":{"name":"probe","arguments":'.json_encode($arguments).'}}]}}]}}',
            '{"request_id":"q2","usage":{"input_tokens":1,"output_tokens":1},"output":{"choices":[{"finish_reason":"stop","message":{"role":"assistant","content":"'.$final.'"}}]}}',
        ],
        'openai' => [
            '{"id":"r1","object":"response","created_at":1,"status":"completed","model":"m","usage":{"input_tokens":1,"output_tokens":1,"total_tokens":2},"output":[{"id":"fc_1","type":"function_call","status":"completed","call_id":"call_1","name":"probe","arguments":'.json_encode($arguments).'}]}',
            '{"id":"r2","object":"response","created_at":1,"status":"completed","model":"m","usage":{"input_tokens":1,"output_tokens":1,"total_tokens":2},"output":[{"id":"m1","type":"message","status":"completed","role":"assistant","content":[{"type":"output_text","text":"'.$final.'","annotations":[]}]}]}',
        ],
        default => [
            '{"id":"c1","object":"chat.completion","created":1,"model":"m","usage":{"prompt_tokens":1,"completion_tokens":1,"total_tokens":2},"choices":[{"index":0,"finish_reason":"tool_calls","message":{"role":"assistant","content":"","tool_calls":[{"id":"call_1","type":"function","function":{"name":"probe","arguments":'.json_encode($arguments).'}}]}}]}',
            '{"id":"c2","object":"chat.completion","created":1,"model":"m","usage":{"prompt_tokens":1,"completion_tokens":1,"total_tokens":2},"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"'.$final.'"}}]}',
        ],
    };
}

/**
 * @return array{0: Tool, 1: Closure(): array{checks: int, runs: int}}
 */
function countingProbeTool(): array
{
    $checks = 0;
    $runs = 0;
    $tool = (new Tool)->as('probe')->for('probe')->withStringParameter('amount', 'amount', false)
        ->requiresApproval(function () use (&$checks): bool {
            $checks++;

            return false;
        })
        ->using(function (mixed ...$received) use (&$runs): string {
            $runs++;

            return 'ran';
        });

    return [$tool, function () use (&$checks, &$runs): array {
        return ['checks' => $checks, 'runs' => $runs];
    }];
}

dataset('arguments a reply can carry but a request cannot', [
    'a number past the float range' => ['{"amount":1e999}'],
    'a nested negative one' => ['{"a":{"b":[-1e999]}}'],
    'an integer past the float range' => ['{"amount":1'.str_repeat('0', 400).'}'],
    'a key starting with NUL' => ['{"amount":"5","\u0000a":1}'],
]);

it('answers an unusable call and sends the follow-up on a text turn', function (string $label, string $arguments): void {
    Http::preventStrayRequests();
    $provider = configureMalformedProvider($label);
    [$first, $second] = nonStreamingBodies($label, $arguments);
    Http::fake(['*' => Http::sequence()->push($first, 200)->push($second, 200)]);
    [$tool, $counts] = countingProbeTool();

    $response = Prism::text()->using($provider, 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asText();

    expect($counts())->toBe(['checks' => 0, 'runs' => 0])
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and(Http::recorded())->toHaveCount(2)
        ->and(replayedToolArguments(json_decode(secondRequestBody(), false)))->toBeIn(['{}', 'omitted']);
})->with('streaming providers')->with('arguments a reply can carry but a request cannot');

it('answers an unusable call and sends the follow-up on a structured turn', function (string $label, string $arguments): void {
    Http::preventStrayRequests();
    $provider = configureMalformedProvider($label);
    [$first, $second] = nonStreamingBodies($label, $arguments);
    // Mistral asks for the structured answer in a request of its own.
    Http::fake(['*' => Http::sequence()->push($first, 200)->push($second, 200)->whenEmpty(Http::response($second, 200))]);
    [$tool, $counts] = countingProbeTool();

    $response = Prism::structured()->using($provider, 'm')
        ->withSchema(new ObjectSchema('result', 'result', [new BooleanSchema('ok', 'ok')], ['ok']))
        ->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asStructured();

    expect($counts())->toBe(['checks' => 0, 'runs' => 0])
        ->and($response->structured)->toBe(['ok' => true])
        ->and(Http::recorded())->toHaveCount($label === 'mistral' ? 3 : 2)
        ->and(replayedToolArguments(json_decode(Http::recorded()[1][0]->body(), false)))->toBeIn(['{}', 'omitted']);
})->with(['anthropic', 'gemini', 'vertex', 'mistral', 'openai', 'openrouter', 'requesty'])->with('arguments a reply can carry but a request cannot');

it('replays a server tool input no request can carry as empty', function (): void {
    Http::preventStrayRequests();
    configureMalformedProvider('anthropic');
    [, $second] = nonStreamingBodies('anthropic', '{}');
    $first = '{"id":"m1","type":"message","role":"assistant","model":"m","stop_reason":"tool_use","usage":{"input_tokens":1,"output_tokens":1},"content":['
        .'{"type":"server_tool_use","id":"srv_1","name":"web_search","input":{"query":1e999}},'
        .'{"type":"web_search_tool_result","tool_use_id":"srv_1","content":[]},'
        .'{"type":"tool_use","id":"call_1","name":"probe","input":{"amount":"5"}}]}';
    Http::fake(['*' => Http::sequence()->push($first, 200)->push($second, 200)]);
    [$tool, $counts] = countingProbeTool();

    Prism::text()->using('anthropic', 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asText();

    $blocks = json_decode(secondRequestBody(), true)['messages'][1]['content'];

    expect($counts())->toBe(['checks' => 1, 'runs' => 1])
        ->and($blocks[0]['type'])->toBe('server_tool_use')
        ->and($blocks[0]['input'])->toBe([])
        ->and($blocks[2]['input'])->toBe(['amount' => '5']);
});
