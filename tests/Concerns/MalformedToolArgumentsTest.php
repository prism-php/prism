<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
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
