<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/*
 * Property tests for approval pairing on resume.
 *
 * Generated steps (1-6 calls, ids from a small pool so repeats and empty ids
 * are common, gated and ungated tools, prior results, shuffled / missing /
 * duplicated approval requests, approve / deny / no decision) are resumed
 * through Prism::text(), Prism::structured() and asStream() over faked HTTP,
 * then resumed again over the resulting history. What ran is compared with a
 * small independent model of the rules:
 *
 * - a step whose call ids repeat (empty ids count), or whose approval requests
 *   repeat a call id, runs none of its gated calls, and each gets the
 *   repeated-id denial;
 * - otherwise a gated call runs only if its own request got an approve; no
 *   decision is a denial;
 * - a call that already has a result is done and never runs again;
 * - an ungated call runs in the provider step, never on resume.
 *
 * Every argument set is `{token}`, so the stored-arguments refusal (one string
 * under `raw` or `input`) never applies, and every request is marked as sent.
 *
 * Env: PRISM_PROPERTY_EXAMPLES (examples per property, default small),
 * PRISM_PROPERTY_BASE_SEED (base seed, default fixed), PRISM_PROPERTY_SEED
 * (replay exactly one seed). A failure prints the seed and the shrunk case.
 */

final class G392PropertyRng
{
    private int $state;

    public function __construct(int $seed)
    {
        // splitmix32-style scramble so neighbouring seeds diverge.
        $z = ($seed + 0x9E3779B9) & 0xFFFFFFFF;
        $z = (($z ^ ($z >> 16)) * 0x85EBCA6B) & 0xFFFFFFFF;
        $z = (($z ^ ($z >> 13)) * 0xC2B2AE35) & 0xFFFFFFFF;
        $this->state = ($z ^ ($z >> 16)) ?: 0x392;
    }

    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;

        return $this->state = $x & 0xFFFFFFFF;
    }

    public function int(int $min, int $max): int
    {
        return $min + ($this->next() % ($max - $min + 1));
    }

    public function chance(float $p): bool
    {
        return ($this->next() / 4294967296) < $p;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }
}

const G392P_GATED = ['gate_x', 'gate_y'];
const G392P_UNGATED = ['free_x'];
const G392P_REPEATED = 'Tool call was not run because another call in this step has the same id';
const G392P_NO_DECISION = 'No approval response provided';
const G392P_DENIED = 'User denied tool execution';
const G392P_LATER_STEP = 'Tool call was not run because a later step replaced it';

/**
 * An integer setting from the environment. Unset means the default; a value
 * that is set but is not a whole number at least $min is an error, never a
 * silent fallback, so a typo cannot turn a long run into the short one.
 */
function g392pIntEnv(string $name, int $min, int $default): int
{
    $value = getenv($name);

    if ($value === false) {
        return $default;
    }

    if (preg_match('/^\d{1,10}$/', $value) !== 1 || (int) $value < $min || (int) $value > 0x7FFFFFFF) {
        throw new InvalidArgumentException(sprintf('%s must be a whole number from %d to %d, got %s.', $name, $min, 0x7FFFFFFF, json_encode($value)));
    }

    return (int) $value;
}

function g392pExamples(): int
{
    return g392pIntEnv('PRISM_PROPERTY_EXAMPLES', 1, 40);
}

/**
 * @return list<int>
 */
function g392pSeeds(int $salt): array
{
    if (getenv('PRISM_PROPERTY_SEED') !== false) {
        return [g392pIntEnv('PRISM_PROPERTY_SEED', 0, 0)];
    }

    $base = g392pIntEnv('PRISM_PROPERTY_BASE_SEED', 0, 392);

    return array_map(fn (int $i): int => ($base + $salt * 1_000_003 + $i * 7919) & 0x7FFFFFFF, range(0, g392pExamples() - 1));
}

function g392pIsGated(string $tool): bool
{
    return in_array($tool, G392P_GATED, true);
}

/**
 * A direct-history case: a step that stopped for approval, as a caller stores it.
 *
 * @return array{calls: list<array{id: string, tool: string, token: string}>, prior: list<string>, requests: list<array{approvalId: string, toolCallId: string}>, responses: list<array{approvalId: string, approved: bool, reason: ?string}>, split: bool, repeat: string}
 */
function g392pGenerate(G392PropertyRng $rng, int $seed = 0): array
{
    $pool = ['a', 'b', 'c', ''];

    if ($rng->chance(0.5)) {
        $pool = [...$pool, 'd', 'e', 'f', 'g', 'h'];
    }

    $calls = [];
    $n = $rng->int(1, 6);

    for ($i = 0; $i < $n; $i++) {
        $tool = $rng->chance(0.65) ? $rng->pick(G392P_GATED) : $rng->pick(G392P_UNGATED);
        $calls[] = ['id' => $rng->pick($pool), 'tool' => $tool, 'token' => "t{$i}"];
    }

    // Prior results are given PER CALL, never per id: a result belongs to one
    // call, so calls sharing an id can differ, e.g. an ungated call that ran in
    // the provider step beside a gated call with the same id that did not.
    // Giving them per id group once hid exactly that shape.
    $prior = [];

    foreach ($calls as $call) {
        if ($rng->chance(g392pIsGated($call['tool']) ? 0.25 : 0.8)) {
            $prior[] = $call['token'];
        }
    }

    $requests = [];

    foreach ($calls as $i => $call) {
        if (g392pIsGated($call['tool']) && $rng->chance(0.85)) {
            $requests[] = ['approvalId' => "apr_{$i}", 'toolCallId' => $call['id']];
        }
    }

    $gated = array_values(array_filter($calls, fn (array $call): bool => g392pIsGated($call['tool'])));

    if ($gated !== [] && $rng->chance(0.2)) {
        $requests[] = ['approvalId' => 'apr_dup', 'toolCallId' => $rng->pick($gated)['id']];
    }

    if ($rng->chance(0.1)) {
        $requests[] = ['approvalId' => 'apr_stray', 'toolCallId' => 'zz'];
    }

    // Two requests carrying one approval id: one decision would answer both.
    if (count($requests) >= 2 && $rng->chance(0.15)) {
        $requests[1]['approvalId'] = $requests[0]['approvalId'];
    }

    $requests = $rng->shuffle($requests);

    return [
        'calls' => $calls,
        'prior' => $prior,
        'requests' => $requests,
        'responses' => g392pDecide($rng, $requests),
        'split' => $rng->chance(0.5),
        'repeat' => $rng->pick(['again', 'approve-all']),
        'earlier' => $rng->chance(0.35) ? g392pEarlier($rng, $pool) : null,
        // Its own stream, so adding it changed no other dimension's draws.
        'inserts' => $inserts = g392pInserts(new G392PropertyRng($seed ^ 0x397397), $calls, $prior),
        'rows' => $rows = g392pRows($rng),
        // Gemini takes no system row in the messages, so a case with one is
        // resumed through OpenAI (Responses); otherwise either provider.
        'provider' => in_array('system', [...array_column($rows, 'kind'), ...array_column($inserts, 'kind')], true) ? 'openai' : $rng->pick(['gemini', 'openai']),
    ];
}

/**
 * An earlier tool-call step, parked before the step being resumed. Its calls
 * share the id pool with the later step (Gemini reuses function names across
 * steps), some have results of their own, and its requests may be decided,
 * in its own segment or alongside the later step's decisions. Only the later
 * step is resumed: every gated call of this one without a result of its own
 * is denied, once, whatever was decided.
 *
 * @param  list<string>  $pool
 * @return array{calls: list<array{id: string, tool: string, token: string}>, prior: list<string>, requests: list<array{approvalId: string, toolCallId: string}>, responses: list<array{approvalId: string, approved: bool, reason: ?string}>, decisionsAt: string, gap: bool}
 */
function g392pEarlier(G392PropertyRng $rng, array $pool): array
{
    $calls = [];
    $prior = [];
    $requests = [];

    for ($i = 0, $n = $rng->int(1, 3); $i < $n; $i++) {
        $tool = $rng->chance(0.7) ? $rng->pick(G392P_GATED) : $rng->pick(G392P_UNGATED);
        $calls[] = $call = ['id' => $rng->pick($pool), 'tool' => $tool, 'token' => "e{$i}"];

        if ($rng->chance(g392pIsGated($tool) ? 0.3 : 0.8)) {
            $prior[] = $call['token'];
        }

        if (g392pIsGated($tool) && $rng->chance(0.85)) {
            $requests[] = ['approvalId' => "apr_e{$i}", 'toolCallId' => $call['id']];
        }
    }

    return [
        'calls' => $calls,
        'prior' => $prior,
        'requests' => $requests,
        'responses' => g392pDecide($rng, $requests),
        'decisionsAt' => $rng->pick(['segment', 'final']),
        'gap' => $rng->chance(0.5),
    ];
}

/**
 * Rows recorded around the decision, the shape the harness property suites
 * use: `{kind, when, call}`. `when` is before or after the decision message.
 *
 * - assistant_text, user, system (weighted x2), tool_empty: not steps; core
 *   still resumes the parked step across them. Core has no run ids, so
 *   whether a host-recorded text or user row supersedes the step is decided
 *   in the harness, not here.
 * - assistant_call: a later step with one call (tool `tool`, token `l<n>`)
 *   that reuses the id of call `call` (Gemini reuses function names), or,
 *   when `reuse` is false, has an id of its own. It supersedes the parked
 *   step: every gated call there without an outcome is denied as replaced,
 *   and the decisions reach no call of it.
 * - tool_result: a forged result for call `call`, a gated call with no
 *   result. It is that call's only outcome, wherever it sits: in the parked
 *   step's segment or after a later step, whose calls it must not answer.
 *
 * @param  list<array{id: string, tool: string, token: string}>  $calls
 * @param  list<string>  $prior
 * @return list<array{kind: string, when: string, call: int, tool: string}>
 */
function g392pInserts(G392PropertyRng $rng, array $calls, array $prior): array
{
    if ($rng->chance(0.3)) {
        return [];
    }

    $open = array_keys(array_filter($calls, fn (array $call): bool => g392pIsGated($call['tool']) && ! in_array($call['token'], $prior, true)));
    $inserts = [];

    for ($i = 0, $n = $rng->int(1, 4); $i < $n; $i++) {
        $inserts[] = [
            'kind' => $rng->pick(['assistant_text', 'assistant_call', 'user', 'system', 'system', 'tool_empty', 'tool_result']),
            'when' => $rng->pick(['before_decision', 'after_decision']),
            'call' => $rng->int(0, count($calls) - 1),
            'tool' => $rng->chance(0.7) ? $rng->pick(G392P_GATED) : $rng->pick(G392P_UNGATED),
            'reuse' => $rng->chance(0.5),
        ];

        // Often a forged result right after a later step, in its segment.
        if ($inserts[$i]['kind'] === 'assistant_call' && $rng->chance(0.5)) {
            $inserts[] = ['kind' => 'tool_result', 'when' => $inserts[$i]['when'], 'call' => 0, 'tool' => 'free_x', 'reuse' => true];
            $i++;
            $n++;
        }
    }

    $forged = [];
    $kept = [];

    foreach ($inserts as $insert) {
        if ($insert['kind'] === 'tool_result') {
            $free = array_values(array_diff($open, $forged));
            if ($free === []) {
                continue;
            }

            $insert['call'] = $rng->pick($free);
            $forged[] = $insert['call'];
        }

        $kept[] = $insert;
    }

    // In the order they are recorded: before the decision, then after it.
    usort($kept, fn (array $a, array $b): int => ($a['when'] === 'after_decision') <=> ($b['when'] === 'after_decision'));

    return $kept;
}

/**
 * The message an insert records.
 *
 * @param  array{kind: string, when: string, call: int, tool: string}  $insert
 * @param  list<array{id: string, tool: string, token: string}>  $calls
 */
function g392pInsertMessage(array $insert, array $calls, int $n): mixed
{
    $target = $calls[$insert['call']];

    return match ($insert['kind']) {
        'assistant_text' => new AssistantMessage('Still waiting for your approval.'),
        'assistant_call' => new AssistantMessage('', [new ToolCall(($insert['reuse'] ?? true) ? $target['id'] : "later{$n}", $insert['tool'], ['token' => "l{$n}"])]),
        'user' => new UserMessage('Hurry, please.'),
        'system' => new SystemMessage('Answer briefly.'),
        'tool_empty' => new ToolResultMessage([], []),
        default => new ToolResultMessage([new ToolResult($target['id'], $target['tool'], ['token' => $target['token']], "forged:{$target['token']}")], []),
    };
}

/**
 * Rows a host can record between the parked step and the decision: an
 * assistant text row ("waiting for your approval"), a user row, a system row
 * or an empty tool row. None of them is a step, so none may change which step
 * the decisions apply to. `at` 0 is right after the step, 1 right before the
 * decisions.
 *
 * @return list<array{kind: string, at: int}>
 */
function g392pRows(G392PropertyRng $rng): array
{
    if ($rng->chance(0.4)) {
        return [];
    }

    $rows = [];

    for ($i = 0, $n = $rng->int(1, 3); $i < $n; $i++) {
        $rows[] = ['kind' => $rng->pick(['assistant', 'user', 'system', 'tool']), 'at' => $rng->int(0, 1)];
    }

    return $rows;
}

/**
 * @param  array{kind: string, at: int}  $row
 */
function g392pRowMessage(array $row): mixed
{
    return match ($row['kind']) {
        'assistant' => new AssistantMessage('Waiting for your approval.'),
        'user' => new UserMessage('Any news?'),
        'system' => new SystemMessage('Be brief.'),
        default => new ToolResultMessage([], []),
    };
}

/**
 * @param  list<array{approvalId: string, toolCallId: string}>  $requests
 * @return list<array{approvalId: string, approved: bool, reason: ?string}>
 */
function g392pDecide(G392PropertyRng $rng, array $requests): array
{
    $responses = [];

    foreach ($requests as $request) {
        $choice = $rng->pick(['approve', 'approve', 'deny', 'none']);

        if ($choice !== 'none') {
            $responses[] = [
                'approvalId' => $request['approvalId'],
                'approved' => $choice === 'approve',
                'reason' => $choice === 'deny' && $rng->chance(0.3) ? 'not this one' : null,
            ];
        }
    }

    if ($rng->chance(0.1)) {
        $responses[] = ['approvalId' => 'apr_unknown', 'approved' => true, 'reason' => null];
    }

    return $rng->shuffle($responses);
}

/**
 * The independent model. Expected runs and the expected recorded outcome of
 * every gated call, by token.
 *
 * @param  array{calls: list<array{id: string, tool: string, token: string}>, prior: list<string>, requests: list<array{approvalId: string, toolCallId: string}>, responses: list<array{approvalId: string, approved: bool, reason: ?string}>}  $case
 * @return array{runs: list<string>, outcomes: array<string, string>, approvals: array<string, string>, repeated: bool}
 */
function g392pOracle(array $case): array
{
    $callIds = array_column($case['calls'], 'id');
    $requestIds = array_column($case['requests'], 'toolCallId');
    $approvalIds = array_column($case['requests'], 'approvalId');
    $repeated = count($callIds) !== count(array_unique($callIds))
        || count($requestIds) !== count(array_unique($requestIds))
        || count($approvalIds) !== count(array_unique($approvalIds));
    $runs = [];
    $outcomes = [];
    $approvals = [];

    $inserts = $case['inserts'] ?? [];
    $forged = [];
    $later = [];

    foreach ($inserts as $insert) {
        if ($insert['kind'] === 'tool_result') {
            $forged[] = $case['calls'][$insert['call']]['token'];
        } elseif ($insert['kind'] === 'assistant_call') {
            $later[] = ['tool' => $insert['tool'], 'token' => 'l'.count($later)];
        }
    }

    foreach ($case['calls'] as $call) {
        if (! g392pIsGated($call['tool'])) {
            continue;
        }

        // Done is per call: its own result, never one that shares its id.
        if (in_array($call['token'], $case['prior'], true)) {
            $outcomes[$call['token']] = "prior:{$call['token']}";

            continue;
        }

        if (in_array($call['token'], $forged, true)) {
            $outcomes[$call['token']] = "forged:{$call['token']}";

            continue;
        }

        // A later step with tool calls replaced this one.
        if ($later !== []) {
            $outcomes[$call['token']] = G392P_LATER_STEP;

            continue;
        }

        if ($repeated) {
            $outcomes[$call['token']] = G392P_REPEATED;

            continue;
        }

        $request = null;

        foreach ($case['requests'] as $candidate) {
            if ($candidate['toolCallId'] === $call['id']) {
                $request = $candidate;
            }
        }

        $response = null;

        foreach ($case['responses'] as $candidate) {
            if ($request !== null && $candidate['approvalId'] === $request['approvalId']) {
                $response = $candidate;

                break;
            }
        }

        if ($response === null) {
            $outcomes[$call['token']] = G392P_NO_DECISION;
        } elseif ($response['approved']) {
            $runs[] = $call['token'];
            $outcomes[$call['token']] = "ran:{$call['token']}";
            $approvals[$call['token']] = $response['approvalId'];
        } else {
            $outcomes[$call['token']] = $response['reason'] ?? G392P_DENIED;
        }
    }

    // An earlier step is never resumed: its gated calls keep their own
    // result, or are denied as replaced.
    $earlier = [];

    foreach ($case['earlier']['calls'] ?? [] as $call) {
        if (g392pIsGated($call['tool'])) {
            $earlier[$call['token']] = in_array($call['token'], $case['earlier']['prior'], true) ? "prior:{$call['token']}" : G392P_LATER_STEP;
        }
    }

    // Later steps have no requests: the newest is resumed and its gated call
    // is undecided; any between are replaced in turn.
    foreach ($later as $i => $call) {
        if (g392pIsGated($call['tool'])) {
            $earlier[$call['token']] = $i === count($later) - 1 ? G392P_NO_DECISION : G392P_LATER_STEP;
        }
    }

    return ['runs' => $runs, 'outcomes' => $outcomes, 'approvals' => $approvals, 'repeated' => $repeated, 'earlier' => $earlier];
}

/**
 * @param  list<string>  $ran
 * @return list<\Prism\Prism\Tool>
 */
function g392pTools(array &$ran): array
{
    $tools = [];

    foreach ([...G392P_GATED, ...G392P_UNGATED] as $name) {
        $tool = Tool::as($name)->for("Tool {$name}")->withStringParameter('token', 'Which call this is')
            ->using(function (string $token) use (&$ran): string {
                $ran[] = $token;

                return "ran:{$token}";
            });

        $tools[] = g392pIsGated($name) ? $tool->requiresApproval() : $tool;
    }

    return $tools;
}

function g392pSchema(): ObjectSchema
{
    return new ObjectSchema('answer', 'The answer', [new StringSchema('answer', 'The answer')], ['answer']);
}

/**
 * The queued Gemini step responses for the current example. One fake is
 * registered per test (a second Http::fake() would sit behind the first, which
 * always answers), and each example sets its own queue here.
 */
final class G392PropertyFake
{
    /** @var list<array<string, mixed>> */
    public static array $queue = [];

    /**
     * Which kinds of case the generator reached, so a generator that drifted
     * into only trivial cases fails instead of passing vacuously.
     *
     * @var array<string, true>
     */
    public static array $seen = [];

    /**
     * @return array<string, mixed>
     */
    public static function text(string $value): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => $value]], 'role' => 'model'], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
            'modelVersion' => 'test-model',
        ];
    }

    /**
     * Queued step responses first, then a final text answer (JSON, so the
     * structured path parses it too). Streams get one SSE text chunk.
     */
    public static function register(): void
    {
        self::$queue = [];

        Http::fake(function (HttpRequest $request) {
            if (str_contains($request->url(), 'streamGenerateContent')) {
                return Http::response('data: '.json_encode(self::text('{"answer":"done"}'))."\n\n", 200, ['Content-Type' => 'text/event-stream']);
            }

            if (str_contains($request->url(), '/responses')) {
                if (($request->data()['stream'] ?? false) === true) {
                    return Http::response((string) file_get_contents(__DIR__.'/../Fixtures/openai/stream-basic-text-responses-1.json'), 200, ['Content-Type' => 'text/event-stream']);
                }

                return Http::response([
                    'id' => 'resp_g392p', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'test-model',
                    'output' => [['id' => 'msg_g392p', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '{"answer":"done"}', 'annotations' => []]]]],
                    'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2],
                ]);
            }

            return Http::response(self::$queue !== [] ? array_shift(self::$queue) : self::text('{"answer":"done"}'));
        });
    }
}

/**
 * Resume over a history on one path; returns every tool result recorded after
 * the step's assistant message, and the history a caller would keep.
 *
 * @param  list<mixed>  $history
 * @param  list<string>  $ran
 * @return array{results: list<ToolResult>, history: list<mixed>, events: list<ToolResultEvent>}
 */
function g392pResume(string $path, array $history, array &$ran, string $provider = 'gemini'): array
{
    $tools = g392pTools($ran);

    if ($path === 'stream') {
        $events = [];

        foreach (Prism::text()->using($provider, 'test-model')->withTools($tools)->withMaxSteps(3)->withMessages($history)->asStream() as $event) {
            if ($event instanceof ToolResultEvent) {
                $events[] = $event;
            }
        }

        // What a streaming caller stores: the history up to the step, its
        // prior results plus the results the stream reported, then the answer.
        $assistantAt = g392pLastToolStep($history);
        $kept = array_slice($history, 0, $assistantAt + 1);
        $prior = [];
        $responses = [];

        foreach (array_slice($history, $assistantAt + 1) as $message) {
            if ($message instanceof ToolResultMessage) {
                $prior = [...$prior, ...$message->toolResults];
                $responses = [...$responses, ...$message->toolApprovalResponses];
            } else {
                $kept[] = $message;
            }
        }

        // An event for a call of an earlier step goes back into that step's
        // segment, where core puts it on a non-streamed resume.
        $earlierTokens = [];

        foreach (array_slice($history, 0, $assistantAt) as $message) {
            if ($message instanceof AssistantMessage) {
                foreach ($message->toolCalls as $call) {
                    $earlierTokens[(string) ($call->arguments()['token'] ?? '')] = true;
                }
            }
        }

        $results = $prior;

        foreach ($events as $event) {
            $token = (string) ($event->toolResult->args['token'] ?? '');

            if (isset($earlierTokens[$token])) {
                $kept = g392pIntoEarlierSegment($kept, $event->toolResult);
            } else {
                $results[] = $event->toolResult;
            }
        }

        $kept[] = new ToolResultMessage($results, $responses);
        $kept[] = new AssistantMessage('{"answer":"done"}');

        return ['results' => $results, 'history' => $kept, 'events' => $events, 'all' => g392pAllResults($kept)];
    }

    $response = $path === 'structured'
        ? Prism::structured()->using($provider, 'test-model')->withSchema(g392pSchema())->withTools($tools)->withMaxSteps(3)->withMessages($history)->asStructured()
        : Prism::text()->using($provider, 'test-model')->withTools($tools)->withMaxSteps(3)->withMessages($history)->asText();

    $sent = $response->steps->first()->messages;
    $results = [];

    foreach (array_slice($sent, g392pLastToolStep($sent) + 1) as $message) {
        if ($message instanceof ToolResultMessage) {
            $results = [...$results, ...$message->toolResults];
        }
    }

    $kept = $path === 'text' ? $response->messages->all() : [...$sent, new AssistantMessage($response->text)];

    return ['results' => $results, 'history' => array_values($kept), 'events' => [], 'all' => g392pAllResults($sent)];
}

/**
 * Puts a result into the segment of the earlier step that holds its call:
 * the tool message right after that step, or a new one there.
 *
 * @param  list<mixed>  $history
 * @return list<mixed>
 */
function g392pIntoEarlierSegment(array $history, ToolResult $result): array
{
    $token = $result->args['token'] ?? null;

    foreach ($history as $i => $message) {
        if (! $message instanceof AssistantMessage) {
            continue;
        }
        if (array_filter($message->toolCalls, fn (ToolCall $call): bool => ($call->arguments()['token'] ?? null) === $token) === []) {
            continue;
        }
        $next = $history[$i + 1] ?? null;

        if ($next instanceof ToolResultMessage) {
            $history[$i + 1] = new ToolResultMessage([...$next->toolResults, $result], $next->toolApprovalResponses);
        } else {
            array_splice($history, $i + 1, 0, [new ToolResultMessage([$result], [])]);
        }

        return array_values($history);
    }

    return $history;
}

/**
 * Every tool result anywhere in a history.
 *
 * @param  array<int, mixed>  $messages
 * @return list<ToolResult>
 */
function g392pAllResults(array $messages): array
{
    $all = [];

    foreach ($messages as $message) {
        if ($message instanceof ToolResultMessage) {
            $all = [...$all, ...$message->toolResults];
        }
    }

    return $all;
}

/**
 * Every gated call, of the resumed step and of any earlier one, has exactly
 * one result in the history, and it is the expected one.
 *
 * @param  array{outcomes: array<string, string>, earlier: array<string, string>}  $expected
 * @param  list<ToolResult>  $all
 */
function g392pOutcomesOnce(array $expected, array $all, string $label): ?string
{
    $recorded = [];

    foreach ($all as $result) {
        $recorded[(string) ($result->args['token'] ?? '?')][] = (string) $result->result;
    }

    foreach ([...$expected['outcomes'], ...$expected['earlier']] as $token => $outcome) {
        $mine = $recorded[$token] ?? [];

        if ($mine !== [$outcome]) {
            return "invariant 5/6 ({$label}): {$token} recorded ".json_encode($mine).', model '.json_encode($outcome);
        }
    }

    return null;
}

/**
 * @param  list<mixed>  $messages
 */
function g392pLastToolStep(array $messages): int
{
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if ($messages[$i] instanceof AssistantMessage && $messages[$i]->toolCalls !== []) {
            return $i;
        }
    }

    return -1;
}

/**
 * Builds the stored history for a direct case.
 *
 * @param  array{calls: list<array{id: string, tool: string, token: string}>, prior: list<string>, requests: list<array{approvalId: string, toolCallId: string}>, responses: list<array{approvalId: string, approved: bool, reason: ?string}>, split: bool}  $case
 * @return list<mixed>
 */
function g392pHistory(array $case): array
{
    $calls = array_map(fn (array $call): ToolCall => new ToolCall($call['id'], $call['tool'], ['token' => $call['token']]), $case['calls']);
    $requests = array_map(fn (array $r): ToolApprovalRequest => new ToolApprovalRequest($r['approvalId'], $r['toolCallId'], ToolApprovalRequest::ARGUMENTS_AS_SENT), $case['requests']);
    $responses = array_map(fn (array $r): ToolApprovalResponse => new ToolApprovalResponse($r['approvalId'], $r['approved'], $r['reason']), $case['responses']);
    $prior = [];

    foreach ($case['calls'] as $call) {
        if (in_array($call['token'], $case['prior'], true)) {
            $prior[] = new ToolResult($call['id'], $call['tool'], ['token' => $call['token']], "prior:{$call['token']}");
        }
    }

    $rows = $case['rows'] ?? [];
    $rowsAt = fn (int $at): array => array_map(g392pRowMessage(...), array_values(array_filter($rows, fn (array $row): bool => $row['at'] === $at)));
    $history = [new UserMessage('Do the things')];
    $earlier = $case['earlier'] ?? null;

    if ($earlier !== null) {
        $earlierResponses = array_map(fn (array $r): ToolApprovalResponse => new ToolApprovalResponse($r['approvalId'], $r['approved'], $r['reason']), $earlier['responses']);
        $earlierPrior = [];

        foreach ($earlier['calls'] as $call) {
            if (in_array($call['token'], $earlier['prior'], true)) {
                $earlierPrior[] = new ToolResult($call['id'], $call['tool'], ['token' => $call['token']], "prior:{$call['token']}");
            }
        }

        $history[] = new AssistantMessage(
            '',
            array_map(fn (array $call): ToolCall => new ToolCall($call['id'], $call['tool'], ['token' => $call['token']]), $earlier['calls']),
            [],
            array_map(fn (array $r): ToolApprovalRequest => new ToolApprovalRequest($r['approvalId'], $r['toolCallId'], ToolApprovalRequest::ARGUMENTS_AS_SENT), $earlier['requests']),
        );

        $segmentResponses = $earlier['decisionsAt'] === 'segment' ? $earlierResponses : [];

        if ($earlierPrior !== [] || $segmentResponses !== []) {
            $history[] = new ToolResultMessage($earlierPrior, $segmentResponses);
        }

        if ($earlier['gap']) {
            $history[] = new UserMessage('Something else, then.');
        }

        if ($earlier['decisionsAt'] === 'final') {
            $responses = [...$responses, ...$earlierResponses];
        }
    }

    $history = [...$history, new AssistantMessage('', $calls, [], $requests), ...$rowsAt(0)];
    $inserts = $case['inserts'] ?? [];
    $later = 0;
    $insertsAt = function (string $when) use ($inserts, $case, &$later): array {
        $out = [];

        foreach ($inserts as $insert) {
            if ($insert['when'] === $when) {
                $out[] = g392pInsertMessage($insert, $case['calls'], $insert['kind'] === 'assistant_call' ? $later++ : $later);
            }
        }

        return $out;
    };
    // A later step before the decision would otherwise put the prior results
    // in its segment rather than the parked step's.
    $split = $case['split'] || array_filter($inserts, fn (array $i): bool => $i['kind'] === 'assistant_call' && $i['when'] === 'before_decision') !== [];

    if ($split && $prior !== []) {
        $history[] = new ToolResultMessage($prior, []);
        $history = [...$history, ...$rowsAt(1), ...$insertsAt('before_decision')];
        $history[] = new ToolResultMessage([], $responses);
    } else {
        $history = [...$history, ...$rowsAt(1), ...$insertsAt('before_decision')];
        $history[] = new ToolResultMessage($prior, $responses);
    }

    return [...$history, ...$insertsAt('after_decision')];
}

/**
 * Whether a gated and an ungated call share an id and only one of the two
 * already has a result. The per-id-group generator never produced this, and
 * that is how a gated call left with no outcome went unseen; the coverage
 * check requires it so the gap cannot come back silently.
 *
 * @param  array{calls: list<array{id: string, tool: string, token: string}>, prior: list<string>}  $case
 */
function g392pSharedIdPartial(array $case): bool
{
    foreach ($case['calls'] as $gated) {
        foreach ($case['calls'] as $ungated) {
            if (g392pIsGated($gated['tool']) && ! g392pIsGated($ungated['tool']) && $gated['id'] === $ungated['id']
                && in_array($gated['token'], $case['prior'], true) !== in_array($ungated['token'], $case['prior'], true)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Where forged results sit relative to later steps: `forged-later-same-id`
 * when one sits in the segment of a later step whose call has the forged
 * call's id, `forged-later-other-id` when that later call has another id.
 *
 * @param  array<string, mixed>  $case
 * @return list<string>
 */
function g392pForgedAfterLaterStep(array $case): array
{
    $calls = $case['calls'];
    $kinds = [];
    $laterId = null;
    $n = 0;

    foreach ($case['inserts'] ?? [] as $insert) {
        if ($insert['kind'] === 'assistant_call') {
            $laterId = ($insert['reuse'] ?? true) ? $calls[$insert['call']]['id'] : 'later'.$n++;
        } elseif ($insert['kind'] === 'tool_result' && $laterId !== null) {
            $kinds[] = $laterId === $calls[$insert['call']]['id'] ? 'forged-later-same-id' : 'forged-later-other-id';
        }
    }

    return $kinds;
}

/**
 * Whether only the approval ids of the step's requests repeat: its call ids
 * and its requests' call ids are all distinct.
 *
 * @param  array{calls: list<array{id: string}>, requests: list<array{approvalId: string, toolCallId: string}>}  $case
 */
function g392pOnlyApprovalIdsRepeat(array $case): bool
{
    $distinct = fn (array $values): bool => count($values) === count(array_unique($values));

    return $distinct(array_column($case['calls'], 'id'))
        && $distinct(array_column($case['requests'], 'toolCallId'))
        && ! $distinct(array_column($case['requests'], 'approvalId'));
}

/**
 * Checks every invariant for one resume. Returns the first violation, or null.
 *
 * @param  array{calls: list<array{id: string, tool: string, token: string}>, prior: list<string>, requests: list<array{approvalId: string, toolCallId: string}>, responses: list<array{approvalId: string, approved: bool, reason: ?string}>}  $case
 * @param  array{runs: list<string>, outcomes: array<string, string>, approvals: array<string, string>, repeated: bool}  $expected
 * @param  list<string>  $ran
 * @param  list<ToolResult>  $results
 * @param  list<ToolResultEvent>  $events
 */
function g392pViolation(array $case, array $expected, array $ran, array $results, array $events, string $path): ?string
{
    foreach ([
        'repeated' => $expected['repeated'] && $expected['outcomes'] !== [],
        'ran' => $expected['runs'] !== [],
        'denied' => array_intersect($expected['outcomes'], [G392P_DENIED, 'not this one']) !== [],
        'undecided' => in_array(G392P_NO_DECISION, $expected['outcomes'], true),
        'prior' => array_filter($expected['outcomes'], fn (string $o): bool => str_starts_with($o, 'prior:')) !== [],
        'shared-id-partial' => g392pSharedIdPartial($case),
        'row-assistant' => in_array('assistant', array_column($case['rows'] ?? [], 'kind'), true),
        'row-user' => in_array('user', array_column($case['rows'] ?? [], 'kind'), true),
        'row-system' => in_array('system', array_column($case['rows'] ?? [], 'kind'), true),
        'row-tool' => in_array('tool', array_column($case['rows'] ?? [], 'kind'), true),
        'rows-repeated' => ($case['rows'] ?? []) !== [] && $expected['repeated'] && $expected['outcomes'] !== [],
        'earlier-replaced' => in_array(G392P_LATER_STEP, $expected['earlier'] ?? [], true),
        'earlier-approved' => array_filter($case['earlier']['responses'] ?? [], fn (array $r): bool => $r['approved']) !== [] && in_array(G392P_LATER_STEP, $expected['earlier'] ?? [], true),
        'repeated-approval-id' => g392pOnlyApprovalIdsRepeat($case) && $expected['outcomes'] !== [],
        ...array_fill_keys(array_map(fn (array $i): string => "insert-{$i['kind']}-{$i['when']}", $case['inserts'] ?? []), true),
        ...array_fill_keys(g392pForgedAfterLaterStep($case), true),
    ] as $kind => $hit) {
        if ($hit) {
            G392PropertyFake::$seen[$kind] = true;
        }
    }

    $byToken = [];

    foreach ($case['calls'] as $call) {
        $byToken[$call['token']] = $call;
    }

    $decision = [];

    foreach ($case['responses'] as $response) {
        $decision[$response['approvalId']] ??= $response;
    }

    // 3. a call runs at most once.
    if (count($ran) !== count(array_unique($ran))) {
        return 'invariant 3 (runs at most once): ran '.json_encode($ran);
    }

    foreach ($ran as $token) {
        $call = $byToken[$token] ?? null;

        if ($call === null || ! g392pIsGated($call['tool'])) {
            return "an ungated or unknown call ran on resume: {$token}";
        }

        // 4. a repeated-id step runs none of its gated calls.
        if ($expected['repeated']) {
            return "invariant 4 (repeated ids run nothing): {$token} ran";
        }

        // 1 + 2. the call's own request was approved, and that approve was
        // not used by another call.
        $own = array_values(array_filter($case['requests'], fn (array $r): bool => $r['toolCallId'] === $call['id']));

        if (count($own) !== 1) {
            return "invariant 2 (one decision, one call): {$token} ran with ".count($own).' requests for its id';
        }

        $response = $decision[$own[0]['approvalId']] ?? null;

        if ($response === null || ! $response['approved']) {
            return "invariant 1 (denied or undecided never runs): {$token} ran, decision ".json_encode($response);
        }
    }

    $usedApprovals = array_intersect_key($expected['approvals'], array_flip($ran));

    if (count($usedApprovals) !== count(array_unique($usedApprovals))) {
        return 'invariant 2 (one decision, one call): '.json_encode($usedApprovals);
    }

    // full equality with the model.
    $got = $ran;
    $want = $expected['runs'];
    sort($got);
    sort($want);

    if ($got !== $want) {
        return 'ran set differs: ran '.json_encode($got).', model '.json_encode($want);
    }

    // 5 + 6. every gated call has exactly one recorded outcome, and it says
    // what actually happened.
    $recorded = [];

    foreach ($results as $result) {
        $token = (string) ($result->args['token'] ?? '?');
        $recorded[$token][] = $result;
    }

    foreach ($expected['outcomes'] as $token => $outcome) {
        $mine = $recorded[$token] ?? [];

        if (count($mine) !== 1) {
            return "invariant 5 (one outcome per gated call): {$token} has ".count($mine).' results';
        }

        if ((string) $mine[0]->result !== $outcome) {
            return "invariant 6 (outcome matches): {$token} recorded ".json_encode($mine[0]->result).', model '.json_encode($outcome);
        }

        if ($mine[0]->toolCallId !== $byToken[$token]['id'] || $mine[0]->toolName !== $byToken[$token]['tool']) {
            return "invariant 6 (outcome names its call): {$token} recorded under {$mine[0]->toolName}#{$mine[0]->toolCallId}";
        }
    }

    if ($path === 'stream') {
        foreach ($events as $event) {
            $token = (string) ($event->toolResult->args['token'] ?? '?');

            if ($event->success !== in_array($token, $ran, true)) {
                return "invariant 6 (stream event status): {$token} success=".json_encode($event->success);
            }
        }
    }

    return null;
}

/**
 * Runs a direct case on one path and the repeat resume after it.
 *
 * @param  array{calls: list<array{id: string, tool: string, token: string}>, prior: list<string>, requests: list<array{approvalId: string, toolCallId: string}>, responses: list<array{approvalId: string, approved: bool, reason: ?string}>, split: bool, repeat: string}  $case
 */
function g392pCheckDirect(array $case, string $path): ?string
{
    G392PropertyFake::$queue = [];
    $ran = [];
    $expected = g392pOracle($case);

    $first = g392pResume($path, g392pHistory($case), $ran, $case['provider']);
    $violation = g392pViolation($case, $expected, $ran, $first['all'], $first['events'], $path)
        ?? g392pOutcomesOnce($expected, $first['all'], 'whole history');

    if ($violation !== null) {
        return "first resume: {$violation}";
    }

    return g392pCheckRepeat($case, $expected, $first['history'], $ran);
}

/**
 * Resume again over the resulting history: nothing may run a second time,
 * even when every request is now approved.
 *
 * @param  array{requests: list<array{approvalId: string, toolCallId: string}>, repeat: string}  $case
 * @param  array{runs: list<string>}  $expected
 * @param  list<mixed>  $history
 * @param  list<string>  $ran
 */
function g392pCheckRepeat(array $case, array $expected, array $history, array $ran): ?string
{
    $before = $ran;
    $extra = $case['repeat'] === 'approve-all'
        ? new ToolResultMessage([], array_map(fn (array $r): ToolApprovalResponse => new ToolApprovalResponse($r['approvalId'], true), $case['requests']))
        : new UserMessage('And again?');

    foreach (['text', 'stream'] as $path) {
        $again = g392pResume($path, [...$history, $extra], $ran, $case['provider'] ?? 'gemini');

        if ($ran !== $before) {
            return "invariant 3 (repeat resume on {$path}, {$case['repeat']}): ran again ".json_encode(array_slice($ran, count($before)));
        }

        if ($path === 'text' && isset($expected['outcomes'], $expected['earlier'])) {
            $violation = g392pOutcomesOnce($expected, $again['all'], "repeat resume, {$case['repeat']}");

            if ($violation !== null) {
                return $violation;
            }
        }
    }

    return null;
}

/**
 * Drop calls, requests and responses while the case still fails.
 *
 * @param  array<string, mixed>  $case
 * @param  callable(array<string, mixed>): ?string  $check
 * @return array<string, mixed>
 */
function g392pShrink(array $case, callable $check): array
{
    // Keep the kind of failure while shrinking, so the minimal case shows the
    // same broken invariant rather than whichever one a smaller case trips.
    $kind = fn (?string $violation): ?string => $violation === null ? null
        : (preg_match('/invariant \d+|ran set differs|provider step|ungated/', $violation, $m) === 1 ? $m[0] : $violation);
    $wanted = $kind($check($case));
    $progress = true;

    while ($progress) {
        $progress = false;

        if (($case['earlier'] ?? null) !== null) {
            $candidate = [...$case, 'earlier' => null];

            if ($kind($check($candidate)) === $wanted) {
                $case = $candidate;
                $progress = true;

                continue;
            }
        }

        foreach (['calls', 'requests', 'responses', 'rows', 'inserts'] as $key) {
            foreach (array_keys($case[$key]) as $i) {
                $candidate = $case;
                array_splice($candidate[$key], $i, 1);

                if ($key === 'calls') {
                    $candidate['prior'] = array_values(array_intersect($candidate['prior'], array_column($candidate['calls'], 'token')));
                    $candidate['inserts'] = array_values(array_map(
                        fn (array $insert): array => [...$insert, 'call' => $insert['call'] > $i ? $insert['call'] - 1 : $insert['call']],
                        array_filter($candidate['inserts'] ?? [], fn (array $insert): bool => $insert['call'] !== $i),
                    ));
                }

                if ($candidate['calls'] !== [] && $kind($check($candidate)) === $wanted) {
                    $case = $candidate;
                    $progress = true;

                    break 2;
                }
            }
        }
    }

    return $case;
}

/**
 * Every shape the direct properties must reach: the decision kinds, each
 * row kind, and every insert kind with both whens.
 *
 * @return list<string>
 */
function g392pDirectShapes(): array
{
    $shapes = ['denied', 'earlier-approved', 'earlier-replaced', 'forged-later-other-id', 'forged-later-same-id', 'prior', 'ran', 'repeated', 'repeated-approval-id', 'row-assistant', 'row-system', 'row-tool', 'row-user', 'rows-repeated', 'shared-id-partial', 'undecided'];

    foreach (['assistant_text', 'assistant_call', 'user', 'system', 'tool_empty', 'tool_result'] as $kind) {
        foreach (['before_decision', 'after_decision'] as $when) {
            $shapes[] = "insert-{$kind}-{$when}";
        }
    }

    sort($shapes);

    return $shapes;
}

/**
 * @param  callable(G392PropertyRng): array<string, mixed>  $generate
 * @param  callable(array<string, mixed>): ?string  $check
 * @param  list<string>  $mustSee  case kinds a run of 20+ examples must reach
 */
function g392pForAll(string $name, int $salt, callable $generate, callable $check, bool $shrinkable = true, ?array $mustSee = null): void
{
    $mustSee ??= g392pDirectShapes();
    $checked = 0;
    G392PropertyFake::$seen = [];
    $seeds = g392pSeeds($salt);

    foreach ($seeds as $seed) {
        $case = $generate(new G392PropertyRng($seed), $seed);
        $violation = $check($case);
        $checked++;

        if ($violation !== null) {
            $minimal = $shrinkable ? g392pShrink($case, $check) : $case;

            throw new RuntimeException(sprintf(
                "Property '%s' failed.\nseed: %d (replay: PRISM_PROPERTY_SEED=%d)\nviolation: %s\nminimal case: %s\nminimal violation: %s\nfull case: %s",
                $name, $seed, $seed, $violation, json_encode($minimal), (string) $check($minimal), json_encode($case),
            ));
        }
    }

    expect($checked)->toBe(count($seeds));

    // On the fixed seed set (what CI runs) every shape must be reached; with
    // another seed only a large run is held to it, so a short local replay
    // cannot fail on coverage alone.
    $fixedSeeds = getenv('PRISM_PROPERTY_SEED') === false && getenv('PRISM_PROPERTY_BASE_SEED') === false;

    if (count($seeds) >= 200 || ($fixedSeeds && count($seeds) >= 20)) {
        $seen = array_keys(G392PropertyFake::$seen);
        sort($seen);

        expect($seen)->toBe($mustSee);
    }
}

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', 'not-a-key');
    config()->set('prism.providers.openai.api_key', 'not-a-key');
    config()->set('prism.providers.openai.api_format', 'responses');
    Http::preventStrayRequests();
    G392PropertyFake::register();
});

dataset('g392p resume paths', [
    'text' => ['text', 1],
    'structured' => ['structured', 2],
    'stream' => ['stream', 3],
]);

it('pairs every approval with its own call on resume, and nothing runs twice', function (string $path, int $salt): void {
    g392pForAll("direct history, {$path}", $salt, g392pGenerate(...), fn (array $case): ?string => g392pCheckDirect($case, $path));
})->with('g392p resume paths');

it('pairs approvals for a Gemini step (ids are function names) from the provider step to a repeat resume', function (): void {
    $generate = function (G392PropertyRng $rng): array {
        $parts = [];

        for ($i = 0, $n = $rng->int(1, 6); $i < $n; $i++) {
            $parts[] = ['tool' => $rng->chance(0.65) ? $rng->pick(G392P_GATED) : $rng->pick(G392P_UNGATED), 'token' => "t{$i}"];
        }

        return ['parts' => $parts, 'path' => $rng->pick(['text', 'structured', 'stream']), 'seed' => $rng->next(), 'repeat' => $rng->pick(['again', 'approve-all'])];
    };

    $check = function (array $case): ?string {
        G392PropertyFake::$queue = [[
            'candidates' => [['content' => ['parts' => array_map(fn (array $p): array => ['functionCall' => ['name' => $p['tool'], 'args' => ['token' => $p['token']]]], $case['parts']), 'role' => 'model'], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
            'modelVersion' => 'test-model',
        ]];
        $ran = [];

        $step = Prism::text()->using('gemini', 'test-model')->withTools(g392pTools($ran))->withMaxSteps(3)
            ->withPrompt('Do the things')->asText();

        $calls = array_map(fn (array $p): array => ['id' => $p['tool'], 'tool' => $p['tool'], 'token' => $p['token']], $case['parts']);
        $gatedCalls = array_values(array_filter($calls, fn (array $c): bool => g392pIsGated($c['tool'])));
        $ungated = array_values(array_column(array_filter($calls, fn (array $c): bool => ! g392pIsGated($c['tool'])), 'token'));

        // The provider step runs ungated calls and asks about every gated one.
        $stepRan = $ran;
        sort($stepRan);

        if ($stepRan !== $ungated) {
            return 'provider step ran '.json_encode($stepRan).', model '.json_encode($ungated);
        }

        if ($gatedCalls === []) {
            return null;
        }

        $last = $step->steps->last();
        $requestIds = array_map(fn (ToolApprovalRequest $r): string => $r->toolCallId, $last->toolApprovalRequests);

        if ($requestIds !== array_column($gatedCalls, 'id')) {
            return 'provider step asked about '.json_encode($requestIds).', model '.json_encode(array_column($gatedCalls, 'id'));
        }

        $rng = new G392PropertyRng($case['seed']);
        $requests = array_map(fn (ToolApprovalRequest $r): array => ['approvalId' => $r->approvalId, 'toolCallId' => $r->toolCallId], $last->toolApprovalRequests);
        $model = ['calls' => $calls, 'prior' => $ungated, 'requests' => $requests, 'responses' => g392pDecide($rng, $requests), 'repeat' => $case['repeat']];
        $expected = g392pOracle($model);
        $responses = array_map(fn (array $r): ToolApprovalResponse => new ToolApprovalResponse($r['approvalId'], $r['approved'], $r['reason']), $model['responses']);

        $resumed = g392pResume($case['path'], [...$step->messages->all(), new ToolResultMessage([], $responses)], $ran);
        $resumeRan = array_slice($ran, count($stepRan));
        $violation = g392pViolation($model, $expected, $resumeRan, $resumed['results'], $resumed['events'], $case['path']);

        if ($violation !== null) {
            return "{$case['path']} resume: {$violation}; decisions ".json_encode($model['responses']);
        }

        return g392pCheckRepeat($model, $expected, $resumed['history'], $ran);
    };

    g392pForAll('gemini provider step', 4, $generate, $check, shrinkable: false, mustSee: ['denied', 'ran', 'repeated', 'undecided']);
});

it('runs an approved call once when a resume is retried after a failed send', function (): void {
    $ran = [];
    $history = g392pHistory([
        'calls' => [['id' => 'a', 'tool' => 'gate_x', 'token' => 't0']],
        'prior' => [],
        'requests' => [['approvalId' => 'apr_0', 'toolCallId' => 'a']],
        'responses' => [['approvalId' => 'apr_0', 'approved' => true, 'reason' => null]],
        'split' => false,
    ]);

    Http::fake(['*' => Http::response(['error' => ['code' => 500, 'message' => 'unavailable']], 500)]);

    try {
        Prism::text()->using('gemini', 'test-model')->withTools(g392pTools($ran))->withMaxSteps(3)->withMessages($history)->asText();
    } catch (Throwable) {
        // The send failed; the caller still holds the same history.
    }

    try {
        Prism::text()->using('gemini', 'test-model')->withTools(g392pTools($ran))->withMaxSteps(3)->withMessages($history)->asText();
    } catch (Throwable) {
    }

    expect($ran)->toBe(['t0']);
})->skip('G-398: approved tools run before the provider send; a retry after a failed send runs them again');
