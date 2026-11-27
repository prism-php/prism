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
 * - a result answers only calls of the step it is recorded after (its
 *   segment): the call with the same id, name and arguments first, then one
 *   with the same id and name, then one with the same id; a call so answered
 *   is done and never runs again;
 * - every gated call of an earlier step left unanswered is denied as replaced;
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
        'refusal' => g392pRefusal(new G392PropertyRng($seed ^ 0x398398), $pool),
        'forms' => g392pForms(new G392PropertyRng($seed ^ 0x399399)),
        // Its own stream, so adding it changed no other dimension's draws.
        'inserts' => $inserts = g392pInserts(new G392PropertyRng($seed ^ 0x397397), $calls, $prior),
        'rows' => $rows = g392pRows($rng),
        // Gemini takes no system row in the messages, so a case with one is
        // resumed through OpenAI (Responses); otherwise either provider.
        'provider' => in_array('system', [...array_column($rows, 'kind'), ...array_column($inserts, 'kind')], true) ? 'openai' : $rng->pick(['gemini', 'openai']),
        'values' => g392pValues(new G392PropertyRng($seed ^ 0x39A39A), $calls, $prior),
        'spread' => g392pSpread(new G392PropertyRng($seed ^ 0x39B39B), count($prior)),
    ];
}

/**
 * Whether the parked step's prior results are recorded one per row instead of
 * together, and for each (in order) whether its row comes before or after the
 * decision row and where it sits among the others. Rows of one segment are
 * read together: a later row's result with the same id must not displace an
 * earlier row's result of another call.
 *
 * @return list<array{when: string, order: int}>|null
 */
function g392pSpread(G392PropertyRng $rng, int $count): ?array
{
    if ($count < 1 || $rng->chance(0.5)) {
        return null;
    }

    $spread = [];

    for ($i = 0; $i < $count; $i++) {
        $spread[] = ['when' => $rng->pick(['before', 'after']), 'order' => $rng->int(0, 99)];
    }

    return $spread;
}

/**
 * The argument VALUE of each call, and the arguments its stored results
 * carry. Calls are keyed by a unique token, but their arguments need not be
 * unique: a call can repeat another call's arguments exactly, in its own step
 * or another one. A stored result can carry arguments that differ from its
 * call's (stored lossily or redacted). Neither may change which call a
 * result answers inside its segment, or let a call run twice.
 *
 * @param  list<array{id: string, tool: string, token: string}>  $calls
 * @param  list<string>  $prior
 * @return array{arg: array<string, string>, lossy: array<string, array<string, mixed>>}
 */
function g392pValues(G392PropertyRng $rng, array $calls, array $prior): array
{
    $arg = [];

    foreach ($calls as $i => $call) {
        $arg[$call['token']] = $i > 0 && $rng->chance(0.2) ? $arg[$calls[$rng->int(0, $i - 1)]['token']] : $call['token'];
    }

    $mainArgs = array_values($arg);

    foreach (['e0', 'e1', 'e2', 'l0', 'l1', 'l2', 'l3'] as $token) {
        $arg[$token] = $rng->chance(0.3) ? $rng->pick($mainArgs) : $token;
    }

    $lossy = [];

    foreach (['t0', 't1', 't2', 't3', 't4', 't5', 'e0', 'e1', 'e2'] as $token) {
        if ($rng->chance(0.25)) {
            $lossy[$token] = $rng->pick([['token' => 'redacted'], [], ['token' => ($arg[$token] ?? $token).'-stored']]);
        }
    }

    return ['arg' => $arg, 'lossy' => $lossy];
}

/**
 * A call's arguments.
 *
 * @param  array<string, mixed>  $case
 * @return array<string, string>
 */
function g392pCallArgs(array $case, string $token): array
{
    return ['token' => $case['values']['arg'][$token] ?? $token];
}

/**
 * The arguments a stored result for this call carries.
 *
 * @param  array<string, mixed>  $case
 * @return array<string, mixed>
 */
function g392pResultArgs(array $case, string $token): array
{
    return $case['values']['lossy'][$token] ?? g392pCallArgs($case, $token);
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
 * A refused stored call in the resumed step: arguments in the stored form
 * (one string under `raw`), already answered by a refusal, which is recorded
 * without arguments. Usually an earlier step parked before it holds an open
 * call with the same id and name and no arguments. The refusal answers the
 * resumed step's call only; the earlier call is denied as replaced; the
 * refused call is never decided again, even when its request is approved.
 *
 * @param  list<string>  $pool
 * @return array{id: string, tool: string, raw: string, approved: bool, earlierArgless: bool}|null
 */
function g392pRefusal(G392PropertyRng $rng, array $pool): ?array
{
    if ($rng->chance(0.7)) {
        return null;
    }

    return [
        'id' => $rng->pick($pool),
        'tool' => $rng->pick(G392P_GATED),
        'raw' => 'r0',
        'approved' => $rng->chance(0.6),
        'earlierArgless' => $rng->chance(0.75),
    ];
}

/**
 * How each call's arguments are stored: a parsed map, the JSON text a
 * provider streams, or that text spaced out. A no-argument call is stored as
 * [], '' or '{}'. Results always carry parsed arguments, so attribution must
 * compare like with like whatever the call holds.
 *
 * @return array<string, string>
 */
function g392pForms(G392PropertyRng $rng): array
{
    $forms = [];

    foreach (['t0', 't1', 't2', 't3', 't4', 't5', 'e0', 'e1', 'e2', 'l0', 'l1', 'l2', 'l3', 'raw', 'none'] as $key) {
        $forms[$key] = $key === 'none' ? $rng->pick(['array', 'empty', 'object']) : $rng->pick(['array', 'string', 'spaced']);
    }

    return $forms;
}

/**
 * A call's arguments in the stored form the case chose for it.
 *
 * @param  array<string, mixed>  $arguments
 * @return string|array<string, mixed>
 */
function g392pStored(array $case, string $key, array $arguments): string|array
{
    return match ($case['forms'][$key] ?? 'array') {
        'string' => (string) json_encode($arguments),
        'spaced' => str_replace([':', ','], [' : ', ' , '], '{ '.substr((string) json_encode($arguments), 1, -1).' }'),
        'empty' => '',
        'object' => '{}',
        default => $arguments,
    };
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
 *   result, recorded in the parked step's segment or after a later step. It
 *   answers only calls of the step it is recorded after: after a later step it
 *   answers that step's call with the same id, and the parked call is denied
 *   as replaced.
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
            $inserts[] = ['kind' => 'tool_result', 'when' => $inserts[$i]['when'], 'call' => 0, 'tool' => 'free_x', 'reuse' => true, 'prefer' => $inserts[$i]['call']];
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

            // Sometimes the call whose id the later step just reused.
            $insert['call'] = isset($insert['prefer']) && in_array($insert['prefer'], $free, true) && $rng->chance(0.6) ? $insert['prefer'] : $rng->pick($free);
            unset($insert['prefer']);
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
function g392pInsertMessage(array $insert, array $calls, int $n, array $case = []): mixed
{
    $target = $calls[$insert['call']];

    return match ($insert['kind']) {
        'assistant_text' => new AssistantMessage('Still waiting for your approval.'),
        'assistant_call' => new AssistantMessage('', [new ToolCall(($insert['reuse'] ?? true) ? $target['id'] : "later{$n}", $insert['tool'], g392pStored($case, "l{$n}", isset($case['values']) ? g392pCallArgs($case, "l{$n}") : ['token' => "l{$n}"]))]),
        'user' => new UserMessage('Hurry, please.'),
        'system' => new SystemMessage('Answer briefly.'),
        'tool_empty' => new ToolResultMessage([], []),
        default => new ToolResultMessage([new ToolResult($target['id'], $target['tool'], isset($case['values']) ? g392pResultArgs($case, $target['token']) : ['token' => $target['token']], "forged:{$target['token']}")], []),
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
 * The model for one provider step (the Gemini source): calls with unique
 * tokens, prior results for the ungated calls that ran in the step, and the
 * requests the step issued. Expected runs and the outcome of every gated
 * call, by token. (Direct histories are checked by g392pPredict instead.)
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

    foreach ($case['calls'] as $call) {
        if (! g392pIsGated($call['tool'])) {
            continue;
        }

        if (in_array($call['token'], $case['prior'], true)) {
            $outcomes[$call['token']] = "prior:{$call['token']}";

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

    return ['runs' => $runs, 'outcomes' => $outcomes, 'approvals' => $approvals, 'repeated' => $repeated];
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
        $earlierCalls = [];

        foreach (array_slice($history, 0, $assistantAt) as $message) {
            if ($message instanceof AssistantMessage) {
                $earlierCalls = [...$earlierCalls, ...$message->toolCalls];
            }
        }

        $results = $prior;

        foreach ($events as $event) {
            if (array_filter($earlierCalls, fn (ToolCall $call): bool => g392pNames($call, $event->toolResult)) !== []) {
                $kept = g392pIntoEarlierSegment($kept, $event->toolResult);
            } else {
                $results[] = $event->toolResult;
            }
        }

        $kept[] = new ToolResultMessage($results, $responses);
        $kept[] = new AssistantMessage('{"answer":"done"}');

        return ['results' => $results, 'history' => $kept, 'events' => $events, 'all' => g392pAllResults($kept), 'sent' => $kept];
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

    return ['results' => $results, 'history' => array_values($kept), 'events' => [], 'all' => g392pAllResults($sent), 'sent' => array_values($sent)];
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
    foreach ($history as $i => $message) {
        if (! $message instanceof AssistantMessage) {
            continue;
        }
        if (array_filter($message->toolCalls, fn (ToolCall $call): bool => g392pNames($call, $result)) === []) {
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
 * Whether a result names this call: its id, tool and arguments.
 */
function g392pNames(ToolCall $call, ToolResult $result): bool
{
    return $result->toolCallId === $call->id && $result->toolName === $call->name && $result->args == $call->arguments();
}

/**
 * The steps of a history: each assistant message with tool calls, with the
 * results and decisions recorded after it up to the next such message (its
 * segment).
 *
 * @param  array<int, mixed>  $messages
 * @return list<array{calls: list<ToolCall>, requests: list<ToolApprovalRequest>, results: list<ToolResult>, responses: list<ToolApprovalResponse>}>
 */
function g392pSteps(array $messages): array
{
    $steps = [];

    foreach (array_values($messages) as $message) {
        if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
            $steps[] = ['calls' => array_values($message->toolCalls), 'requests' => array_values($message->toolApprovalRequests), 'results' => [], 'responses' => []];
        } elseif ($message instanceof ToolResultMessage && $steps !== []) {
            $last = count($steps) - 1;
            $steps[$last]['results'] = [...$steps[$last]['results'], ...$message->toolResults];
            $steps[$last]['responses'] = [...$steps[$last]['responses'], ...$message->toolApprovalResponses];
        }
    }

    return $steps;
}

/**
 * @param  array<mixed>  $arguments
 * @return array<mixed>
 */
function g392pSorted(array $arguments): array
{
    ksort($arguments);

    return array_map(fn (mixed $v): mixed => is_array($v) ? g392pSorted($v) : $v, $arguments);
}

/**
 * Whether a call's arguments are in the stored form earlier versions gave
 * arguments they could not parse: one string under `raw` or `input`.
 */
function g392pWrapped(ToolCall $call): bool
{
    $arguments = $call->arguments();

    return count($arguments) === 1 && in_array(array_key_first($arguments), ['raw', 'input'], true) && is_string(reset($arguments));
}

/**
 * Which result of a segment answers which call of its step: the same id,
 * name and arguments first, then a result without arguments for a call in
 * the stored form, then the same id and name, then the same id. Each result
 * answers one call; the calls are taken in order.
 *
 * @param  list<ToolCall>  $calls
 * @param  list<ToolResult>  $results
 * @return array<int, int> call index => result index
 */
function g392pAnswers(array $calls, array $results): array
{
    $passes = [
        fn (ToolCall $c, ToolResult $r): bool => $r->toolCallId === $c->id && $r->toolName === $c->name && g392pSorted($r->args) === g392pSorted($c->arguments()),
        fn (ToolCall $c, ToolResult $r): bool => $r->args === [] && $r->toolCallId === $c->id && $r->toolName === $c->name && g392pWrapped($c),
        fn (ToolCall $c, ToolResult $r): bool => $r->toolCallId === $c->id && $r->toolName === $c->name,
        fn (ToolCall $c, ToolResult $r): bool => $r->toolCallId === $c->id,
    ];
    $answers = [];
    $taken = [];

    foreach ($passes as $matches) {
        foreach ($calls as $i => $call) {
            if (isset($answers[$i])) {
                continue;
            }

            foreach ($results as $j => $result) {
                if (! isset($taken[$j]) && $matches($call, $result)) {
                    $answers[$i] = $j;
                    $taken[$j] = true;

                    break;
                }
            }
        }
    }

    return $answers;
}

/**
 * A result as the model compares it: id, name, sorted arguments and content;
 * a refusal's message is not predicted, only that it is one.
 */
function g392pKey(string $id, string $name, array $arguments, string $content): string
{
    if ($arguments === [] && str_starts_with($content, 'Tool call arguments')) {
        $content = '*refused*';
    }

    return json_encode([$id, $name, g392pSorted($arguments)]).'|'.$content;
}

/**
 * What one resume over this history must do, by the rules: the values of the
 * calls that run, and the results added to each step's segment.
 *
 * @param  array<int, mixed>  $messages
 * @return array{runs: list<string>, added: array<int, list<string>>, repeated: bool, answeredBefore: bool}
 */
function g392pPredict(array $messages): array
{
    $steps = g392pSteps($messages);
    $added = array_fill(0, count($steps), []);
    $runs = [];
    $answeredBefore = false;
    $repeated = false;

    foreach ($steps as $k => $step) {
        $answers = g392pAnswers($step['calls'], $step['results']);

        foreach ($step['calls'] as $i => $call) {
            if (isset($answers[$i]) && g392pIsGated($call->name)) {
                $answeredBefore = true;
            }
        }

        $newest = $k === count($steps) - 1;

        if (! $newest) {
            foreach ($step['calls'] as $i => $call) {
                if (! isset($answers[$i]) && g392pIsGated($call->name)) {
                    $added[$k][] = g392pKey($call->id, $call->name, $call->arguments(), G392P_LATER_STEP);
                }
            }

            continue;
        }

        if (array_filter($step['calls'], fn (ToolCall $c): bool => g392pIsGated($c->name)) === []) {
            continue;
        }

        $unique = fn (array $values): bool => count($values) === count(array_unique($values));
        $repeated = ! $unique(array_map(fn (ToolCall $c): string => $c->id, $step['calls']))
            || ! $unique(array_map(fn (ToolApprovalRequest $r): string => $r->toolCallId, $step['requests']))
            || ! $unique(array_map(fn (ToolApprovalRequest $r): string => $r->approvalId, $step['requests']));
        $requestFor = [];

        foreach ($step['requests'] as $request) {
            $requestFor[$request->toolCallId] = $request;
        }

        foreach ($step['calls'] as $i => $call) {
            if (isset($answers[$i])) {
                continue;
            }
            if (! g392pIsGated($call->name)) {
                continue;
            }
            if ($repeated) {
                $added[$k][] = g392pKey($call->id, $call->name, $call->arguments(), G392P_REPEATED);

                continue;
            }

            $request = $requestFor[$call->id] ?? null;
            $response = null;

            foreach ($step['responses'] as $candidate) {
                if ($request !== null && $candidate->approvalId === $request->approvalId) {
                    $response = $candidate;

                    break;
                }
            }

            if (! $response instanceof ToolApprovalResponse) {
                $added[$k][] = g392pKey($call->id, $call->name, $call->arguments(), G392P_NO_DECISION);
            } elseif (! $response->approved) {
                $added[$k][] = g392pKey($call->id, $call->name, $call->arguments(), $response->reason ?? G392P_DENIED);
            } elseif (g392pWrapped($call)) {
                $added[$k][] = g392pKey($call->id, $call->name, [], 'Tool call arguments');
            } else {
                $runs[] = (string) $call->arguments()['token'];
                $added[$k][] = g392pKey($call->id, $call->name, $call->arguments(), 'ran:'.$call->arguments()['token']);
            }
        }
    }

    return ['runs' => $runs, 'added' => $added, 'repeated' => $repeated, 'answeredBefore' => $answeredBefore];
}

/**
 * The results added to each step's segment between two histories, and
 * whether any result was lost.
 *
 * @param  array<int, mixed>  $before
 * @param  array<int, mixed>  $after
 * @return array{added: array<int, list<string>>, addedAt: array<int, list<int>>, lost: bool, steps: int}
 */
function g392pAdded(array $before, array $after): array
{
    $old = g392pSteps($before);
    $new = g392pSteps($after);
    $added = [];
    $addedAt = [];
    $lost = count($old) !== count($new);

    foreach ($new as $k => $step) {
        $remaining = array_map(fn (ToolResult $r): string => g392pKey($r->toolCallId, $r->toolName, $r->args, (string) $r->result), $old[$k]['results'] ?? []);
        $added[$k] = [];
        $addedAt[$k] = [];

        foreach ($step['results'] as $j => $result) {
            $key = g392pKey($result->toolCallId, $result->toolName, $result->args, (string) $result->result);
            $at = array_search($key, $remaining, true);

            if ($at === false) {
                $added[$k][] = $key;
                $addedAt[$k][] = $j;
            } else {
                unset($remaining[$at]);
            }
        }

        $lost = $lost || $remaining !== [];
    }

    return ['added' => $added, 'addedAt' => $addedAt, 'lost' => $lost, 'steps' => count($new)];
}

/**
 * One resume over $history on $path, checked against the model: the calls
 * that ran, the results added to each segment (and, on a stream, the events),
 * and afterwards every gated call answered by exactly one result of its own
 * segment, every added result answering a call.
 *
 * @param  list<mixed>  $history
 * @param  list<string>  $ran
 * @return array{violation: ?string, history: list<mixed>, predicted: array{runs: list<string>, added: array<int, list<string>>, repeated: bool, answeredBefore: bool}}
 */
function g392pModelResume(string $path, array $history, array &$ran, string $provider, string $label): array
{
    $predicted = g392pPredict($history);
    $before = count($ran);
    $resumed = g392pResume($path, $history, $ran, $provider);
    $done = fn (?string $violation, array $kept): array => ['violation' => $violation === null ? null : "{$label}: {$violation}", 'history' => $kept, 'predicted' => $predicted];

    $gotRuns = array_slice($ran, $before);
    $wantRuns = $predicted['runs'];
    sort($gotRuns);
    sort($wantRuns);

    if (count(array_slice($ran, $before)) > count($wantRuns) && $wantRuns === []) {
        return $done('invariant 3 (nothing may run): ran '.json_encode($gotRuns), $resumed['history']);
    }

    if ($gotRuns !== $wantRuns) {
        return $done('invariants 1-4 (ran set differs): ran '.json_encode($gotRuns).', model '.json_encode($wantRuns), $resumed['history']);
    }

    if ($path === 'stream') {
        $got = array_map(fn (ToolResultEvent $e): string => g392pKey($e->toolResult->toolCallId, $e->toolResult->toolName, $e->toolResult->args, (string) $e->toolResult->result), $resumed['events']);
        $want = array_merge(...array_values($predicted['added']) ?: [[]]);
        sort($got);
        sort($want);

        if ($got !== $want) {
            return $done('invariant 5/6 (stream results): '.json_encode($got).', model '.json_encode($want), $resumed['history']);
        }

        foreach ($resumed['events'] as $event) {
            if ($event->success !== str_starts_with((string) $event->toolResult->result, 'ran:')) {
                return $done('invariant 6 (stream event status): '.json_encode([$event->toolResult->result, $event->success]), $resumed['history']);
            }
        }

        $kept = g392pKeepStream($history, $resumed['events'], $predicted['added']);
    } else {
        $kept = $resumed['history'];
        $actual = g392pAdded($history, $resumed['sent']);

        if ($actual['lost']) {
            return $done('invariant 5 (a recorded result was lost)', $kept);
        }

        foreach ($predicted['added'] as $k => $want) {
            $got = $actual['added'][$k] ?? [];
            sort($got);
            sort($want);

            if ($got !== $want) {
                return $done("invariant 5/6 (step {$k} results): added ".json_encode($got).', model '.json_encode($want), $kept);
            }
        }
    }

    $final = $path === 'stream' ? $kept : $resumed['sent'];
    $added = g392pAdded($history, $final);

    foreach (g392pSteps($final) as $k => $step) {
        $answers = g392pAnswers($step['calls'], $step['results']);

        foreach ($step['calls'] as $i => $call) {
            if (g392pIsGated($call->name) && ! isset($answers[$i])) {
                return $done("invariant 5 (one outcome per gated call): step {$k} call {$i} ({$call->id}) has none", $kept);
            }
        }

        foreach ($added['addedAt'][$k] ?? [] as $j) {
            if (! in_array($j, $answers, true)) {
                return $done("invariant 5 (one outcome per gated call): step {$k} holds an added result that answers no call", $kept);
            }
        }
    }

    return $done(null, $kept);
}

/**
 * What a streaming caller stores: each event's result put in the segment of
 * the step the model gave it to, then the answer.
 *
 * @param  list<mixed>  $history
 * @param  list<ToolResultEvent>  $events
 * @param  array<int, list<string>>  $added
 * @return list<mixed>
 */
function g392pKeepStream(array $history, array $events, array $added): array
{
    $history = array_values($history);
    $byKey = [];

    foreach ($events as $event) {
        $byKey[g392pKey($event->toolResult->toolCallId, $event->toolResult->toolName, $event->toolResult->args, (string) $event->toolResult->result)][] = $event->toolResult;
    }

    $stepAt = [];

    foreach ($history as $i => $message) {
        if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
            $stepAt[] = $i;
        }
    }

    // Last step first, so earlier positions stay valid.
    foreach (array_reverse(array_keys($stepAt)) as $k) {
        $results = [];

        foreach ($added[$k] ?? [] as $key) {
            $results[] = array_shift($byKey[$key]);
        }

        $results = array_values(array_filter($results));

        if ($results === []) {
            continue;
        }

        $end = $stepAt[$k + 1] ?? count($history);
        $last = null;

        for ($i = $stepAt[$k] + 1; $i < $end; $i++) {
            if ($history[$i] instanceof ToolResultMessage) {
                $last = $i;
            }
        }

        if ($last !== null) {
            $history[$last] = new ToolResultMessage([...$history[$last]->toolResults, ...$results], $history[$last]->toolApprovalResponses);
        } else {
            array_splice($history, $end, 0, [new ToolResultMessage($results, [])]);
        }
    }

    return [...$history, new AssistantMessage('{"answer":"done"}')];
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
    $calls = array_map(fn (array $call): ToolCall => new ToolCall($call['id'], $call['tool'], g392pStored($case, $call['token'], g392pCallArgs($case, $call['token']))), $case['calls']);
    $requests = array_map(fn (array $r): ToolApprovalRequest => new ToolApprovalRequest($r['approvalId'], $r['toolCallId'], ToolApprovalRequest::ARGUMENTS_AS_SENT), $case['requests']);
    $responses = array_map(fn (array $r): ToolApprovalResponse => new ToolApprovalResponse($r['approvalId'], $r['approved'], $r['reason']), $case['responses']);
    $prior = [];

    foreach ($case['calls'] as $call) {
        if (in_array($call['token'], $case['prior'], true)) {
            $prior[] = new ToolResult($call['id'], $call['tool'], g392pResultArgs($case, $call['token']), "prior:{$call['token']}");
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
                $earlierPrior[] = new ToolResult($call['id'], $call['tool'], g392pResultArgs($case, $call['token']), "prior:{$call['token']}");
            }
        }

        $history[] = new AssistantMessage(
            '',
            array_map(fn (array $call): ToolCall => new ToolCall($call['id'], $call['tool'], g392pStored($case, $call['token'], g392pCallArgs($case, $call['token']))), $earlier['calls']),
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

    $refusal = $case['refusal'] ?? null;

    if ($refusal !== null) {
        if ($refusal['earlierArgless']) {
            $history[] = new AssistantMessage('', [new ToolCall($refusal['id'], $refusal['tool'], g392pStored($case, 'none', []))]);
        }

        $calls[] = new ToolCall($refusal['id'], $refusal['tool'], g392pStored($case, 'raw', ['raw' => $refusal['raw']]));
        $prior[] = new ToolResult($refusal['id'], $refusal['tool'], [], "refused:{$refusal['raw']}");

        if ($refusal['approved']) {
            $requests[] = new ToolApprovalRequest('apr_r', $refusal['id'], ToolApprovalRequest::ARGUMENTS_AS_SENT);
            $responses[] = new ToolApprovalResponse('apr_r', true);
        }
    }

    $history = [...$history, new AssistantMessage('', $calls, [], $requests), ...$rowsAt(0)];
    $inserts = $case['inserts'] ?? [];
    $later = 0;
    $insertsAt = function (string $when) use ($inserts, $case, &$later): array {
        $out = [];

        foreach ($inserts as $insert) {
            if ($insert['when'] === $when) {
                $out[] = g392pInsertMessage($insert, $case['calls'], $insert['kind'] === 'assistant_call' ? $later++ : $later, $case);
            }
        }

        return $out;
    };
    // A later step before the decision would otherwise put the prior results
    // in its segment rather than the parked step's.
    $split = $case['split'] || array_filter($inserts, fn (array $i): bool => $i['kind'] === 'assistant_call' && $i['when'] === 'before_decision') !== [];

    $spread = $case['spread'] ?? null;

    if ($spread !== null && $prior !== []) {
        // One row per prior result, some before the decision row and some
        // after it, all in the parked step's segment.
        $rowsFor = function (string $when) use ($spread, $prior): array {
            $picked = [];

            foreach ($prior as $i => $result) {
                $at = $spread[$i] ?? ['when' => 'before', 'order' => $i];

                if ($at['when'] === $when) {
                    $picked[] = [$at['order'], $i, $result];
                }
            }

            usort($picked, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

            return array_map(fn (array $p): ToolResultMessage => new ToolResultMessage([$p[2]], []), $picked);
        };

        $history = [...$history, ...$rowsFor('before'), ...$rowsAt(1), ...$insertsAt('before_decision')];
        $history[] = new ToolResultMessage([], $responses);
        $history = [...$history, ...$rowsFor('after')];
    } elseif ($split && $prior !== []) {
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
 * Which stored argument forms the case used: `args-string` and
 * `args-spaced` for text arguments, `args-string-reused-id` when a call
 * stored as text shares its id with a call of another step, and
 * `args-none-text` for a no-argument call stored as '' or '{}'.
 *
 * @param  array<string, mixed>  $case
 * @return list<string>
 */
function g392pFormShapes(array $case): array
{
    $forms = $case['forms'] ?? [];
    $shapes = [];
    $byStep = ['main' => $case['calls'], 'earlier' => $case['earlier']['calls'] ?? []];
    $n = 0;

    foreach ($case['inserts'] ?? [] as $insert) {
        if ($insert['kind'] === 'assistant_call') {
            $id = ($insert['reuse'] ?? true) ? $case['calls'][$insert['call']]['id'] : "later{$n}";
            $byStep['later'.$n] = [['id' => $id, 'token' => 'l'.$n]];
            $n++;
        }
    }

    foreach ($byStep as $step => $calls) {
        foreach ($calls as $call) {
            $form = $forms[$call['token']] ?? 'array';

            if ($form === 'array') {
                continue;
            }

            $shapes[] = "args-{$form}";

            foreach ($byStep as $other => $otherCalls) {
                if ($other !== $step && in_array($call['id'], array_column($otherCalls, 'id'), true)) {
                    $shapes[] = 'args-string-reused-id';
                }
            }
        }
    }

    if (($case['refusal']['earlierArgless'] ?? false) && in_array($forms['none'] ?? 'array', ['empty', 'object'], true)) {
        $shapes[] = 'args-none-text';
    }

    return array_values(array_unique($shapes));
}

/**
 * Whether the parked step repeats a call id, and its prior results for two
 * calls with that id, carrying different arguments, sit in separate rows of
 * its segment.
 *
 * @param  array<string, mixed>  $case
 */
function g392pRowsRepeatedId(array $case): bool
{
    if (($case['spread'] ?? null) === null) {
        return false;
    }

    $byId = [];

    foreach ($case['calls'] as $call) {
        if (in_array($call['token'], $case['prior'], true)) {
            $byId[$call['id']][] = json_encode(g392pSorted(g392pResultArgs($case, $call['token'])));
        }
    }

    foreach ($byId as $args) {
        if (count(array_unique($args)) >= 2) {
            return true;
        }
    }

    return false;
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
 * Runs a direct case on one path, then resumes again over the resulting
 * history on the text and stream paths: the model is checked on each, and
 * the repeat resumes may run nothing and add nothing.
 *
 * @param  array<string, mixed>  $case
 */
function g392pCheckDirect(array $case, string $path): ?string
{
    G392PropertyFake::$queue = [];
    $ran = [];
    $history = g392pHistory($case);
    $first = g392pModelResume($path, $history, $ran, $case['provider'], 'first resume');
    g392pRecordShapes($case, $first['predicted']);

    if ($first['violation'] !== null) {
        return $first['violation'];
    }

    return g392pCheckRepeat($case, $first['history'], $ran, $case['provider']);
}

/**
 * Resume again over a resolved history: nothing may run a second time and
 * nothing may be added, even when every request is now approved.
 *
 * @param  array<string, mixed>  $case
 * @param  list<mixed>  $history
 * @param  list<string>  $ran
 */
function g392pCheckRepeat(array $case, array $history, array $ran, string $provider = 'gemini'): ?string
{
    $before = $ran;
    $extra = $case['repeat'] === 'approve-all'
        ? new ToolResultMessage([], array_map(fn (array $r): ToolApprovalResponse => new ToolApprovalResponse($r['approvalId'], true), $case['requests']))
        : new UserMessage('And again?');

    foreach (['text', 'stream'] as $path) {
        $again = g392pModelResume($path, [...$history, $extra], $ran, $provider, "repeat resume on {$path}, {$case['repeat']}");

        if ($ran !== $before) {
            return "invariant 3 (repeat resume on {$path}, {$case['repeat']}): ran again ".json_encode(array_slice($ran, count($before)));
        }

        if ($again['predicted']['added'] !== array_fill(0, count($again['predicted']['added']), [])) {
            return "invariant 5 (repeat resume on {$path}): the model still had calls to answer ".json_encode($again['predicted']['added']);
        }

        if ($again['violation'] !== null) {
            return $again['violation'];
        }
    }

    return null;
}

/**
 * The shapes this case reached, for the coverage check.
 *
 * @param  array<string, mixed>  $case
 * @param  array{runs: list<string>, added: array<int, list<string>>, repeated: bool, answeredBefore: bool}  $predicted
 */
function g392pRecordShapes(array $case, array $predicted): void
{
    $added = implode("\n", array_merge(...array_values($predicted['added']) ?: [[]]));
    $replaced = str_contains($added, G392P_LATER_STEP);
    $values = $case['values']['arg'] ?? [];
    $mainArgs = array_map(fn (array $c): string => $values[$c['token']] ?? $c['token'], $case['calls']);
    $otherArgs = [];

    foreach ($case['earlier']['calls'] ?? [] as $call) {
        $otherArgs[] = $values[$call['token']] ?? $call['token'];
    }

    $n = 0;

    foreach ($case['inserts'] ?? [] as $insert) {
        if ($insert['kind'] === 'assistant_call') {
            $otherArgs[] = $values['l'.$n++] ?? '';
        }
    }

    $lossyUsed = array_intersect_key($case['values']['lossy'] ?? [], array_flip([...$case['prior'], ...($case['earlier']['prior'] ?? []), ...array_map(fn (array $i): string => $case['calls'][$i['call']]['token'], array_filter($case['inserts'] ?? [], fn (array $i): bool => $i['kind'] === 'tool_result'))]));

    foreach ([
        'repeated' => $predicted['repeated'] && str_contains($added, G392P_REPEATED),
        'ran' => $predicted['runs'] !== [],
        'denied' => str_contains($added, G392P_DENIED) || str_contains($added, 'not this one'),
        'undecided' => str_contains($added, G392P_NO_DECISION),
        'prior' => $predicted['answeredBefore'],
        'shared-id-partial' => g392pSharedIdPartial($case),
        'row-assistant' => in_array('assistant', array_column($case['rows'] ?? [], 'kind'), true),
        'row-user' => in_array('user', array_column($case['rows'] ?? [], 'kind'), true),
        'row-system' => in_array('system', array_column($case['rows'] ?? [], 'kind'), true),
        'row-tool' => in_array('tool', array_column($case['rows'] ?? [], 'kind'), true),
        'rows-repeated' => ($case['rows'] ?? []) !== [] && $predicted['repeated'],
        'earlier-replaced' => $replaced,
        'earlier-approved' => $replaced && array_filter($case['earlier']['responses'] ?? [], fn (array $r): bool => $r['approved']) !== [],
        'repeated-approval-id' => g392pOnlyApprovalIdsRepeat($case),
        'refusal-earlier-argless' => ($case['refusal']['earlierArgless'] ?? false) === true,
        'refusal-repeated-id' => isset($case['refusal']) && in_array($case['refusal']['id'], array_column($case['calls'], 'id'), true),
        'args-identical-same-step' => count($mainArgs) !== count(array_unique($mainArgs)),
        'args-identical-across-steps' => array_intersect($mainArgs, $otherArgs) !== [],
        'result-args-diverge' => $lossyUsed !== [],
        'results-rows-repeated-id' => g392pRowsRepeatedId($case),
        ...array_fill_keys(g392pFormShapes($case), true),
        ...array_fill_keys(array_map(fn (array $i): string => "insert-{$i['kind']}-{$i['when']}", $case['inserts'] ?? []), true),
        ...array_fill_keys(g392pForgedAfterLaterStep($case), true),
    ] as $kind => $hit) {
        if ($hit) {
            G392PropertyFake::$seen[$kind] = true;
        }
    }
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
    $shapes = ['args-identical-across-steps', 'args-identical-same-step', 'args-none-text', 'args-spaced', 'args-string', 'args-string-reused-id', 'denied', 'earlier-approved', 'earlier-replaced', 'forged-later-other-id', 'forged-later-same-id', 'prior', 'ran', 'refusal-earlier-argless', 'refusal-repeated-id', 'repeated', 'repeated-approval-id', 'result-args-diverge', 'results-rows-repeated-id', 'row-assistant', 'row-system', 'row-tool', 'row-user', 'rows-repeated', 'shared-id-partial', 'undecided'];

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
 * @param  list<string>  $mustSee  case kinds the fixed seed set at 40+ examples, or any run of 200+, must reach
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

    // Every shape must be reached on the fixed seed set at its default size
    // (40, what CI runs) or larger, and by any run of 200 or more examples. A
    // smaller or re-seeded local replay is not held to it, so it cannot fail
    // on coverage alone.
    $fixedSeeds = getenv('PRISM_PROPERTY_SEED') === false && getenv('PRISM_PROPERTY_BASE_SEED') === false;

    if (count($seeds) >= 200 || ($fixedSeeds && count($seeds) >= 40)) {
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

        return g392pCheckRepeat($model, $resumed['history'], $ran);
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
