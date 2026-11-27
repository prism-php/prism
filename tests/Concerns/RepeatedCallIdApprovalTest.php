<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/*
 * Each tool call is decided by its own approval. A provider can return two
 * calls in one step with the same id (Gemini uses the function name as the
 * id), so the id cannot tell their approvals apart. A step whose call ids, or
 * whose approval requests' call ids, repeat is denied: no gated call in it
 * runs, whatever was decided.
 *
 * Driven through Prism::text(), Prism::structured() and asStream() over faked
 * HTTP, offline.
 */

const G392_REASON = 'Tool call was not run because another call in this step has the same id';

function g392GeminiTwoCalls(): array
{
    return [
        'candidates' => [[
            'content' => [
                'parts' => [
                    ['functionCall' => ['name' => 'weather', 'args' => ['city' => 'Paris']]],
                    ['functionCall' => ['name' => 'weather', 'args' => ['city' => 'Rome']]],
                ],
                'role' => 'model',
            ],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
        'modelVersion' => 'test-model',
    ];
}

function g392GeminiText(string $text): array
{
    return [
        'candidates' => [[
            'content' => ['parts' => [['text' => $text]], 'role' => 'model'],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
        'modelVersion' => 'test-model',
    ];
}

function g392ResponsesTwoCalls(string $firstId, string $secondId): array
{
    return [
        'id' => 'resp_g392',
        'object' => 'response',
        'created_at' => 1,
        'status' => 'completed',
        'model' => 'test-model',
        'output' => [
            ['id' => $firstId, 'type' => 'function_call', 'status' => 'completed', 'call_id' => $firstId, 'name' => 'weather', 'arguments' => '{"city":"Paris"}'],
            ['id' => $secondId, 'type' => 'function_call', 'status' => 'completed', 'call_id' => $secondId, 'name' => 'weather', 'arguments' => '{"city":"Rome"}'],
        ],
        'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2],
    ];
}

function g392ResponsesText(string $text): array
{
    return [
        'id' => 'resp_g392_final',
        'object' => 'response',
        'created_at' => 1,
        'status' => 'completed',
        'model' => 'test-model',
        'output' => [['id' => 'msg_g392', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]]],
        'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2],
    ];
}

/**
 * @param  list<string>  $ran
 */
function g392Tool(array &$ran): \Prism\Prism\Tool
{
    return Tool::as('weather')->for('Weather for a city')->withStringParameter('city', 'City')
        ->using(function (string $city) use (&$ran): string {
            $ran[] = $city;

            return "Sunny in {$city}";
        })
        ->requiresApproval();
}

function g392Schema(): ObjectSchema
{
    return new ObjectSchema('answer', 'The answer', [new StringSchema('answer', 'The answer')], ['answer']);
}

/**
 * @param  list<bool>  $decisions  one per approval request, in order
 * @param  list<ToolApprovalRequest>  $requests
 */
function g392Decisions(array $requests, array $decisions): ToolResultMessage
{
    return new ToolResultMessage([], array_map(
        fn (ToolApprovalRequest $request, bool $approved): ToolApprovalResponse => new ToolApprovalResponse($request->approvalId, $approved),
        $requests,
        $decisions,
    ));
}

/**
 * The result each call received on resume, by city, read from the tool
 * result message the resume added to the conversation.
 *
 * @param  array<int, mixed>  $messages
 * @return array<string, string>
 */
function g392ResultsByCity(array $messages): array
{
    $out = [];

    foreach ($messages as $message) {
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                $out[(string) ($result->args['city'] ?? '?')] = (string) $result->result;
            }
        }
    }

    ksort($out);

    return $out;
}

dataset('g392 mixed decisions', [
    'deny the first, approve the second' => [[false, true]],
    'approve the first, deny the second' => [[true, false]],
    'approve both' => [[true, true]],
]);

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', 'not-a-key');
    config()->set('prism.providers.openai.api_key', 'not-a-key');
    config()->set('prism.providers.openai.api_format', 'responses');
    Http::preventStrayRequests();
});

it('runs no gated call of a text step whose two calls share one id, whatever was decided', function (array $decisions): void {
    Http::fakeSequence()->push(g392GeminiTwoCalls())->whenEmpty(Http::response(g392GeminiText('Done.')));
    $ran = [];

    $response = Prism::text()->using('gemini', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withPrompt('Weather in Paris and Rome')->asText();

    $step = $response->steps->last();

    expect($ran)->toBe([])
        ->and($step->toolCalls)->toHaveCount(2)
        ->and($step->toolCalls[0]->id)->toBe($step->toolCalls[1]->id)
        ->and($step->toolApprovalRequests)->toHaveCount(2);

    $resumed = Prism::text()->using('gemini', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([...$response->messages, g392Decisions($step->toolApprovalRequests, $decisions)])
        ->asText();

    expect($ran)->toBe([])
        ->and(g392ResultsByCity($resumed->steps->first()->messages))->toBe(['Paris' => G392_REASON, 'Rome' => G392_REASON]);
})->with('g392 mixed decisions');

it('runs no gated call of a structured step whose two calls share one id, whatever was decided', function (array $decisions): void {
    Http::fakeSequence()->push(g392GeminiTwoCalls())->whenEmpty(Http::response(g392GeminiText('{"answer":"done"}')));
    $ran = [];

    $response = Prism::structured()->using('gemini', 'test-model')->withSchema(g392Schema())->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withPrompt('Weather in Paris and Rome')->asStructured();

    $step = $response->steps->last();

    expect($ran)->toBe([])
        ->and($step->toolApprovalRequests)->toHaveCount(2);

    Prism::structured()->using('gemini', 'test-model')->withSchema(g392Schema())->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([...$step->messages, g392Decisions($step->toolApprovalRequests, $decisions)])
        ->asStructured();

    expect($ran)->toBe([]);
})->with('g392 mixed decisions');

it('runs no gated call of a streamed resume whose two calls share one id, whatever was decided', function (array $decisions): void {
    Http::fake(['*' => Http::response("data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Done.\"}],\"role\":\"model\"},\"finishReason\":\"STOP\"}],\"usageMetadata\":{\"promptTokenCount\":1,\"candidatesTokenCount\":1,\"totalTokenCount\":2},\"modelVersion\":\"test-model\"}\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
    $ran = [];
    $paris = new ToolCall('weather', 'weather', ['city' => 'Paris']);
    $rome = new ToolCall('weather', 'weather', ['city' => 'Rome']);
    $requests = [
        new ToolApprovalRequest('apr_paris', 'weather', ToolApprovalRequest::ARGUMENTS_AS_SENT),
        new ToolApprovalRequest('apr_rome', 'weather', ToolApprovalRequest::ARGUMENTS_AS_SENT),
    ];

    $events = iterator_to_array(Prism::text()->using('gemini', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([
            new UserMessage('Weather in Paris and Rome'),
            new AssistantMessage('', [$paris, $rome], [], $requests),
            g392Decisions($requests, $decisions),
        ])->asStream(), false);

    expect($ran)->toBe([])
        ->and($events)->not->toBeEmpty();
})->with('g392 mixed decisions');

it('runs no gated call when two approval requests name one call id', function (): void {
    Http::fakeSequence()->whenEmpty(Http::response(g392GeminiText('Done.')));
    $ran = [];
    $paris = new ToolCall('weather', 'weather', ['city' => 'Paris']);
    $requests = [
        new ToolApprovalRequest('apr_one', 'weather', ToolApprovalRequest::ARGUMENTS_AS_SENT),
        new ToolApprovalRequest('apr_two', 'weather', ToolApprovalRequest::ARGUMENTS_AS_SENT),
    ];

    $resumed = Prism::text()->using('gemini', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([
            new UserMessage('Weather in Paris'),
            new AssistantMessage('', [$paris], [], $requests),
            g392Decisions($requests, [false, true]),
        ])->asText();

    expect($ran)->toBe([])
        ->and(g392ResultsByCity($resumed->steps->first()->messages))->toBe(['Paris' => G392_REASON]);
});

it('denies a repeated-id step once, and a later turn does not run it either', function (): void {
    Http::fakeSequence()->whenEmpty(Http::response(g392GeminiText('Done.')));
    $ran = [];
    $paris = new ToolCall('weather', 'weather', ['city' => 'Paris']);
    $rome = new ToolCall('weather', 'weather', ['city' => 'Rome']);
    $requests = [
        new ToolApprovalRequest('apr_paris', 'weather', ToolApprovalRequest::ARGUMENTS_AS_SENT),
        new ToolApprovalRequest('apr_rome', 'weather', ToolApprovalRequest::ARGUMENTS_AS_SENT),
    ];

    $first = Prism::text()->using('gemini', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([
            new UserMessage('Weather in Paris and Rome'),
            new AssistantMessage('', [$paris, $rome], [], $requests),
            g392Decisions($requests, [true, true]),
        ])->asText();

    Prism::text()->using('gemini', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([...$first->messages, new UserMessage('And again?')])->asText();

    expect($ran)->toBe([]);
});

it('still decides each call by its own approval when the ids are distinct', function (array $decisions): void {
    Http::fakeSequence()->push(g392ResponsesTwoCalls('call_paris', 'call_rome'))->whenEmpty(Http::response(g392ResponsesText('Done.')));
    $ran = [];

    $response = Prism::text()->using('openai', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withPrompt('Weather in Paris and Rome')->asText();

    $step = $response->steps->last();

    expect($ran)->toBe([])
        ->and($step->toolApprovalRequests)->toHaveCount(2)
        ->and(array_map(fn (ToolApprovalRequest $request): string => $request->toolCallId, $step->toolApprovalRequests))->toBe(['call_paris', 'call_rome']);

    Prism::text()->using('openai', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([...$response->messages, g392Decisions($step->toolApprovalRequests, $decisions)])
        ->asText();

    $expected = array_values(array_filter(['Paris', 'Rome'], fn (string $city, int $i): bool => $decisions[$i], ARRAY_FILTER_USE_BOTH));

    expect($ran)->toBe($expected);
})->with('g392 mixed decisions');

it('runs no gated call of an OpenAI Responses step whose two calls share one id and call_id', function (array $decisions): void {
    Http::fakeSequence()->push(g392ResponsesTwoCalls('call_same', 'call_same'))->whenEmpty(Http::response(g392ResponsesText('Done.')));
    $ran = [];

    $response = Prism::text()->using('openai', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withPrompt('Weather in Paris and Rome')->asText();

    $step = $response->steps->last();

    Prism::text()->using('openai', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([...$response->messages, g392Decisions($step->toolApprovalRequests, $decisions)])
        ->asText();

    expect($ran)->toBe([]);
})->with('g392 mixed decisions');

/*
 * A result belongs to one call. A gated call that shares its id with an
 * ungated call that already ran is not done by that call's result: it has
 * none of its own, so it gets the repeated-id denial, once. Found by the
 * approval-pairing property tests (seeds 1206289, 2032074, 3055834).
 */

/**
 * @param  list<string>  $ran
 * @return list<\Prism\Prism\Tool>
 */
function g392SharedIdTools(array &$ran): array
{
    // A plain loop, not an arrow function: an arrow function captures $ran by
    // value, and the tools would record into a copy.
    $tools = [];

    foreach (['gate_x', 'gate_y', 'free_x'] as $name) {
        $tool = Tool::as($name)->for("Tool {$name}")->withStringParameter('token', 'Which call')
            ->using(function (string $token) use (&$ran): string {
                $ran[] = $token;

                return "ran:{$token}";
            });

        $tools[] = $name === 'free_x' ? $tool : $tool->requiresApproval();
    }

    return $tools;
}

/**
 * Every tool result recorded after the step, by token.
 *
 * @param  array<int, mixed>  $results
 * @return array<string, list<string>>
 */
function g392ResultsByToken(array $results): array
{
    $out = [];

    foreach ($results as $result) {
        $out[(string) ($result->args['token'] ?? '?')][] = (string) $result->result;
    }

    ksort($out);

    return $out;
}

/**
 * @param  list<array{0: string, 1: string, 2: string}>  $calls  [id, tool, token]
 * @param  list<string>  $prior  tokens that already have a result
 * @return list<mixed>
 */
function g392SharedIdHistory(array $calls, array $prior): array
{
    $toolCalls = array_map(fn (array $c): ToolCall => new ToolCall($c[0], $c[1], ['token' => $c[2]]), $calls);
    $results = array_values(array_map(
        fn (array $c): ToolResult => new ToolResult($c[0], $c[1], ['token' => $c[2]], "prior:{$c[2]}"),
        array_filter($calls, fn (array $c): bool => in_array($c[2], $prior, true)),
    ));

    return [
        new UserMessage('Do the things'),
        new AssistantMessage('', $toolCalls, [], []),
        new ToolResultMessage($results, []),
        new ToolResultMessage([], []),
    ];
}

it('denies a gated call once when an ungated call with the same id already ran (text)', function (): void {
    Http::fakeSequence()->whenEmpty(Http::response(g392GeminiText('Done.')));
    $ran = [];

    $resumed = Prism::text()->using('gemini', 'test-model')->withTools(g392SharedIdTools($ran))->withMaxSteps(3)
        ->withMessages(g392SharedIdHistory([['a', 'gate_x', 't0'], ['a', 'free_x', 't1']], ['t1']))
        ->asText();

    $results = [];

    foreach ($resumed->steps->first()->messages as $message) {
        if ($message instanceof ToolResultMessage) {
            $results = [...$results, ...$message->toolResults];
        }
    }

    expect($ran)->toBe([])
        ->and(g392ResultsByToken($results))->toBe(['t0' => [G392_REASON], 't1' => ['prior:t1']]);
});

it('denies a gated call once when an ungated call with the same id already ran (structured)', function (): void {
    Http::fakeSequence()->whenEmpty(Http::response(g392GeminiText('{"answer":"done"}')));
    $ran = [];

    $resumed = Prism::structured()->using('gemini', 'test-model')->withSchema(g392Schema())->withTools(g392SharedIdTools($ran))->withMaxSteps(3)
        ->withMessages(g392SharedIdHistory([['a', 'free_x', 't0'], ['a', 'gate_y', 't5']], ['t0']))
        ->asStructured();

    $results = [];

    foreach ($resumed->steps->first()->messages as $message) {
        if ($message instanceof ToolResultMessage) {
            $results = [...$results, ...$message->toolResults];
        }
    }

    expect($ran)->toBe([])
        ->and(g392ResultsByToken($results))->toBe(['t0' => ['prior:t0'], 't5' => [G392_REASON]]);
});

it('denies a gated call once when an ungated call with the same empty id already ran (stream)', function (): void {
    Http::fake(['*' => Http::response("data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Done.\"}],\"role\":\"model\"},\"finishReason\":\"STOP\"}],\"usageMetadata\":{\"promptTokenCount\":1,\"candidatesTokenCount\":1,\"totalTokenCount\":2},\"modelVersion\":\"test-model\"}\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
    $ran = [];

    $events = iterator_to_array(Prism::text()->using('gemini', 'test-model')->withTools(g392SharedIdTools($ran))->withMaxSteps(3)
        ->withMessages(g392SharedIdHistory([['', 'free_x', 't1'], ['', 'gate_x', 't3']], ['t1']))
        ->asStream(), false);

    $results = array_values(array_map(
        fn (ToolResultEvent $event): ToolResult => $event->toolResult,
        array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolResultEvent),
    ));

    expect($ran)->toBe([])
        ->and(g392ResultsByToken($results))->toBe(['t3' => [G392_REASON]])
        ->and($results[0]->toolCallId)->toBe('')
        ->and($results[0]->toolName)->toBe('gate_x');
});

/*
 * Decisions apply only to the step being resumed: the newest step with tool
 * calls. A gated call in an EARLIER step that has no result of its own is
 * denied, once, whatever was decided for it. And an approval id names one
 * request: a step whose requests repeat an approval id runs none of its
 * gated calls.
 */

const G392_LATER_STEP_REASON = 'Tool call was not run because a later step replaced it';

/**
 * @return array{0: AssistantMessage, 1: AssistantMessage}
 */
function g392OlderAndNewerSteps(): array
{
    return [
        new AssistantMessage('', [new ToolCall('a', 'gate_x', ['token' => 'old'])], [], [new ToolApprovalRequest('apr_old', 'a', ToolApprovalRequest::ARGUMENTS_AS_SENT)]),
        new AssistantMessage('', [new ToolCall('a', 'gate_x', ['token' => 'new'])], [], [new ToolApprovalRequest('apr_new', 'a', ToolApprovalRequest::ARGUMENTS_AS_SENT)]),
    ];
}

/**
 * @param  array<int, mixed>  $messages
 * @return array<string, list<string>>
 */
function g392AllResultsByToken(array $messages): array
{
    $results = [];

    foreach ($messages as $message) {
        if ($message instanceof ToolResultMessage) {
            $results = [...$results, ...$message->toolResults];
        }
    }

    return g392ResultsByToken($results);
}

it('denies, once, a gated call of an earlier step that a later step replaced, whatever was decided for it', function (): void {
    Http::fakeSequence()->whenEmpty(Http::response(g392GeminiText('Done.')));
    $ran = [];
    [$older, $newer] = g392OlderAndNewerSteps();

    $resumed = Prism::text()->using('gemini', 'test-model')->withTools(g392SharedIdTools($ran))->withMaxSteps(3)
        ->withMessages([new UserMessage('Do the things'), $older, $newer, new ToolResultMessage([], [new ToolApprovalResponse('apr_old', true)])])
        ->asText();

    $sent = $resumed->steps->first()->messages;

    expect($ran)->toBe([])
        ->and(g392AllResultsByToken($sent))->toBe(['new' => ['No approval response provided'], 'old' => [G392_LATER_STEP_REASON]])
        ->and($sent[2])->toBeInstanceOf(ToolResultMessage::class)
        ->and($sent[2]->toolResults[0]->args)->toBe(['token' => 'old']);

    // Resumed again, approving everything: nothing runs, and nothing is denied twice.
    $again = Prism::text()->using('gemini', 'test-model')->withTools(g392SharedIdTools($ran))->withMaxSteps(3)
        ->withMessages([...$resumed->messages->all(), new ToolResultMessage([], [new ToolApprovalResponse('apr_old', true), new ToolApprovalResponse('apr_new', true)])])
        ->asText();

    expect($ran)->toBe([])
        ->and(g392AllResultsByToken($again->steps->first()->messages))->toBe(['new' => ['No approval response provided'], 'old' => [G392_LATER_STEP_REASON]]);
});

it('reports the denial of a replaced earlier call on a streamed resume', function (): void {
    Http::fake(['*' => Http::response("data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Done.\"}],\"role\":\"model\"},\"finishReason\":\"STOP\"}],\"usageMetadata\":{\"promptTokenCount\":1,\"candidatesTokenCount\":1,\"totalTokenCount\":2},\"modelVersion\":\"test-model\"}\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
    $ran = [];
    [$older, $newer] = g392OlderAndNewerSteps();

    $events = iterator_to_array(Prism::text()->using('gemini', 'test-model')->withTools(g392SharedIdTools($ran))->withMaxSteps(3)
        ->withMessages([new UserMessage('Do the things'), $older, new ToolResultMessage([], [new ToolApprovalResponse('apr_old', true)]), $newer, new ToolResultMessage([], [new ToolApprovalResponse('apr_new', true)])])
        ->asStream(), false);

    $results = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolResultEvent));

    expect($ran)->toBe(['new'])
        ->and(array_map(fn (ToolResultEvent $event): array => [$event->toolResult->args['token'], $event->success], $results))
        ->toBe([['old', false], ['new', true]])
        ->and($results[0]->toolResult->result)->toBe(G392_LATER_STEP_REASON);
});

it('runs no gated call of a step whose requests repeat an approval id (text)', function (): void {
    Http::fakeSequence()->whenEmpty(Http::response(g392GeminiText('Done.')));
    $ran = [];
    $step = new AssistantMessage('', [new ToolCall('a', 'gate_x', ['token' => 't0']), new ToolCall('b', 'gate_x', ['token' => 't1'])], [], [
        new ToolApprovalRequest('apr_same', 'a', ToolApprovalRequest::ARGUMENTS_AS_SENT),
        new ToolApprovalRequest('apr_same', 'b', ToolApprovalRequest::ARGUMENTS_AS_SENT),
    ]);

    $resumed = Prism::text()->using('gemini', 'test-model')->withTools(g392SharedIdTools($ran))->withMaxSteps(3)
        ->withMessages([new UserMessage('Do the things'), $step, new ToolResultMessage([], [new ToolApprovalResponse('apr_same', true)])])
        ->asText();

    expect($ran)->toBe([])
        ->and(g392AllResultsByToken($resumed->steps->first()->messages))->toBe(['t0' => [G392_REASON], 't1' => [G392_REASON]]);
});

it('runs no gated call of a step whose requests repeat an approval id (structured)', function (): void {
    Http::fakeSequence()->whenEmpty(Http::response(g392GeminiText('{"answer":"done"}')));
    $ran = [];
    $step = new AssistantMessage('', [new ToolCall('a', 'gate_x', ['token' => 't0']), new ToolCall('b', 'gate_y', ['token' => 't1'])], [], [
        new ToolApprovalRequest('apr_same', 'b', ToolApprovalRequest::ARGUMENTS_AS_SENT),
        new ToolApprovalRequest('apr_same', 'a', ToolApprovalRequest::ARGUMENTS_AS_SENT),
    ]);

    Prism::structured()->using('gemini', 'test-model')->withSchema(g392Schema())->withTools(g392SharedIdTools($ran))->withMaxSteps(3)
        ->withMessages([new UserMessage('Do the things'), $step, new ToolResultMessage([], [new ToolApprovalResponse('apr_same', true)])])
        ->asStructured();

    expect($ran)->toBe([]);
});

/*
 * A result answers only calls of the step it is recorded after (its
 * segment): first the call with the same id, name and arguments, then one
 * with the same id and name, then one with the same id. A result recorded
 * after a later step therefore answers that later step's call with the same
 * id, never a call of an earlier step, and the earlier step's open gated
 * call is denied as replaced.
 */

/**
 * @return list<mixed>
 */
function g392ResultAfterLaterStep(string $laterId): array
{
    return [
        new UserMessage('Do the things'),
        new AssistantMessage('', [new ToolCall('a', 'gate_x', ['token' => 't0'])], [], [new ToolApprovalRequest('apr_t0', 'a', ToolApprovalRequest::ARGUMENTS_AS_SENT)]),
        new AssistantMessage('', [new ToolCall($laterId, 'gate_x', ['token' => 'l0'])]),
        new ToolResultMessage([new ToolResult('a', 'gate_x', ['token' => 't0'], 'recorded:t0')], []),
    ];
}

/**
 * The segments expected after one resume of g392ResultAfterLaterStep().
 *
 * @return array<int, list<string>>
 */
function g392ResultAfterLaterStepSegments(string $laterId): array
{
    $later = ['a|gate_x|{"token":"t0"}|recorded:t0'];

    if ($laterId !== 'a') {
        $later[] = $laterId.'|gate_x|{"token":"l0"}|No approval response provided';
    }

    return [1 => ['a|gate_x|{"token":"t0"}|'.G392_LATER_STEP_REASON], 3 => $later];
}

dataset('g392 later step ids', [
    'the later call has another id' => ['b'],
    'the later call reuses the id' => ['a'],
]);

it('keeps a result with the step it is recorded after, and denies the replaced earlier call (text)', function (string $laterId): void {
    Http::fakeSequence()->whenEmpty(Http::response(g392GeminiText('Done.')));
    $ran = [];

    $resumed = Prism::text()->using('gemini', 'test-model')->withTools(g392SharedIdTools($ran))->withMaxSteps(3)
        ->withMessages(g392ResultAfterLaterStep($laterId))
        ->asText();

    expect($ran)->toBe([])
        ->and(g392ResultsBySegment($resumed->steps->first()->messages))->toBe(g392ResultAfterLaterStepSegments($laterId));

    // Resumed again, approving everything: nothing runs and nothing is added.
    $again = Prism::text()->using('gemini', 'test-model')->withTools(g392SharedIdTools($ran))->withMaxSteps(3)
        ->withMessages([...$resumed->messages->all(), new ToolResultMessage([], [new ToolApprovalResponse('apr_t0', true)])])
        ->asText();

    expect($ran)->toBe([])
        ->and(g392ResultsBySegment($again->steps->first()->messages))->toBe(g392ResultAfterLaterStepSegments($laterId));
})->with('g392 later step ids');

it('keeps a result with the step it is recorded after, and denies the replaced earlier call (stream)', function (string $laterId): void {
    Http::fake(['*' => Http::response("data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Done.\"}],\"role\":\"model\"},\"finishReason\":\"STOP\"}],\"usageMetadata\":{\"promptTokenCount\":1,\"candidatesTokenCount\":1,\"totalTokenCount\":2},\"modelVersion\":\"test-model\"}\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
    $ran = [];

    $events = iterator_to_array(Prism::text()->using('gemini', 'test-model')->withTools(g392SharedIdTools($ran))->withMaxSteps(3)
        ->withMessages(g392ResultAfterLaterStep($laterId))
        ->asStream(), false);

    $results = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolResultEvent));
    $expected = [['t0', G392_LATER_STEP_REASON, false]];

    if ($laterId !== 'a') {
        $expected[] = ['l0', 'No approval response provided', false];
    }

    expect($ran)->toBe([])
        ->and(array_map(fn (ToolResultEvent $event): array => [$event->toolResult->args['token'], $event->toolResult->result, $event->success], $results))
        ->toBe($expected);
})->with('g392 later step ids');

/*
 * A call is done when its own step's segment holds its result, even when the
 * stored arguments of that result differ from the call's (stored lossily or
 * redacted), and whatever an earlier step holds.
 */

/**
 * @param  list<string>  $ran
 */
function g392TransferTool(array &$ran): \Prism\Prism\Tool
{
    return Tool::as('transfer')->for('Transfer')->withStringParameter('to', 'To')->withStringParameter('memo', 'Memo', false)
        ->using(function (string $to, ?string $memo = null) use (&$ran): string {
            $ran[] = $to.':'.($memo ?? '-');

            return 'sent';
        })->requiresApproval();
}

dataset('g392 earlier same-id call', [
    'the earlier call has its own result' => [true],
    'the earlier call has no result' => [false],
]);

it('does not run again a call whose recorded result differs from its arguments', function (bool $earlierHasResult): void {
    Http::fakeSequence()->whenEmpty(Http::response(g392GeminiText('Done.')));
    $ran = [];
    $history = [
        new UserMessage('Pay'),
        new AssistantMessage('', [new ToolCall('transfer', 'transfer', ['to' => 'B'])]),
    ];

    if ($earlierHasResult) {
        $history[] = new ToolResultMessage([new ToolResult('transfer', 'transfer', ['to' => 'B'], 'sent:B')], []);
    }

    $history[] = new AssistantMessage('', [new ToolCall('transfer', 'transfer', ['to' => 'B', 'memo' => 'x'])], [], [new ToolApprovalRequest('apr_n', 'transfer', ToolApprovalRequest::ARGUMENTS_AS_SENT)]);
    $history[] = new ToolResultMessage([new ToolResult('transfer', 'transfer', ['to' => 'B'], 'sent:B:x')], [new ToolApprovalResponse('apr_n', true)]);

    $resumed = Prism::text()->using('gemini', 'test-model')->withTools([g392TransferTool($ran)])->withMaxSteps(3)
        ->withMessages($history)
        ->asText();

    $segments = array_values(g392ResultsBySegment($resumed->steps->first()->messages));

    expect($ran)->toBe([])
        ->and($segments)->toBe([
            [$earlierHasResult ? 'transfer|transfer|{"to":"B"}|sent:B' : 'transfer|transfer|{"to":"B"}|'.G392_LATER_STEP_REASON],
            ['transfer|transfer|{"to":"B"}|sent:B:x'],
        ]);
})->with('g392 earlier same-id call');

it('runs a later call identical to an earlier one once on its own approval, and not again', function (): void {
    Http::fakeSequence()->whenEmpty(Http::response(g392GeminiText('Done.')));
    $ran = [];
    $history = [
        new UserMessage('Pay'),
        new AssistantMessage('', [new ToolCall('transfer', 'transfer', ['to' => 'B'])]),
        new ToolResultMessage([new ToolResult('transfer', 'transfer', ['to' => 'B'], 'sent:B')], []),
        new AssistantMessage('', [new ToolCall('transfer', 'transfer', ['to' => 'B'])], [], [new ToolApprovalRequest('apr_l', 'transfer', ToolApprovalRequest::ARGUMENTS_AS_SENT)]),
        new ToolResultMessage([], [new ToolApprovalResponse('apr_l', true)]),
    ];

    $first = Prism::text()->using('gemini', 'test-model')->withTools([g392TransferTool($ran)])->withMaxSteps(3)
        ->withMessages($history)
        ->asText();

    expect($ran)->toBe(['B:-']);

    Prism::text()->using('gemini', 'test-model')->withTools([g392TransferTool($ran)])->withMaxSteps(3)
        ->withMessages([...$first->messages->all(), new ToolResultMessage([], [new ToolApprovalResponse('apr_l', true)])])
        ->asText();

    expect($ran)->toBe(['B:-']);
});

/*
 * A result recorded without arguments (a refused stored call is recorded that
 * way) is attributed by the segment it is recorded in. It answers the call of
 * that step, never a call of another step with the same id, name and empty
 * arguments.
 */

/**
 * The results in each step's segment, by the step's position in $messages.
 *
 * @param  array<int, mixed>  $messages
 * @return array<int, list<string>>
 */
function g392ResultsBySegment(array $messages): array
{
    $messages = array_values($messages);
    $out = [];
    $step = null;

    foreach ($messages as $i => $message) {
        if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
            $step = $i;
            $out[$step] = [];
        } elseif ($message instanceof ToolResultMessage && $step !== null) {
            foreach ($message->toolResults as $result) {
                $out[$step][] = $result->toolCallId.'|'.$result->toolName.'|'.json_encode($result->args).'|'.$result->result;
            }
        }
    }

    return $out;
}

it('keeps a result recorded without arguments with the step it follows', function (string $path): void {
    Http::fakeSequence()->whenEmpty(Http::response(g392GeminiText('{"answer":"done"}')));
    $ran = [];
    $history = [
        new UserMessage('Do the things'),
        new AssistantMessage('', [new ToolCall('a', 'gate_x', [])]),
        new AssistantMessage('', [new ToolCall('a', 'gate_x', ['raw' => 'r'])], [], [new ToolApprovalRequest('apr_r', 'a', ToolApprovalRequest::ARGUMENTS_AS_SENT)]),
        new ToolResultMessage([new ToolResult('a', 'gate_x', [], 'refused:r')], [new ToolApprovalResponse('apr_r', true)]),
    ];

    $resumed = $path === 'structured'
        ? Prism::structured()->using('gemini', 'test-model')->withSchema(g392Schema())->withTools(g392SharedIdTools($ran))->withMaxSteps(3)->withMessages($history)->asStructured()
        : Prism::text()->using('gemini', 'test-model')->withTools(g392SharedIdTools($ran))->withMaxSteps(3)->withMessages($history)->asText();

    expect($ran)->toBe([])
        ->and(g392ResultsBySegment($resumed->steps->first()->messages))->toBe([
            1 => ['a|gate_x|[]|'.G392_LATER_STEP_REASON],
            3 => ['a|gate_x|[]|refused:r'],
        ]);
})->with(['text', 'structured']);
