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

function g392pExamples(): int
{
    $value = getenv('PRISM_PROPERTY_EXAMPLES');

    return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : 40;
}

/**
 * @return list<int>
 */
function g392pSeeds(int $salt): array
{
    $replay = getenv('PRISM_PROPERTY_SEED');

    if (is_string($replay) && ctype_digit($replay)) {
        return [(int) $replay];
    }

    $base = getenv('PRISM_PROPERTY_BASE_SEED');
    $base = is_string($base) && ctype_digit($base) ? (int) $base : 392;

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
function g392pGenerate(G392PropertyRng $rng): array
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

    // Prior results are given per id: the code under test can only tell calls
    // apart by id, so a result names every call that shares it. Ungated calls
    // normally ran in the provider step.
    $prior = [];

    foreach (array_unique(array_column($calls, 'id')) as $id) {
        $group = array_values(array_filter($calls, fn (array $call): bool => $call['id'] === $id));
        $allUngated = array_filter($group, fn (array $call): bool => g392pIsGated($call['tool'])) === [];

        if ($rng->chance($allUngated ? 0.8 : 0.25)) {
            $prior = [...$prior, ...array_column($group, 'token')];
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

    $requests = $rng->shuffle($requests);

    return [
        'calls' => $calls,
        'prior' => $prior,
        'requests' => $requests,
        'responses' => g392pDecide($rng, $requests),
        'split' => $rng->chance(0.5),
        'repeat' => $rng->pick(['again', 'approve-all']),
    ];
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
    $repeated = count($callIds) !== count(array_unique($callIds)) || count($requestIds) !== count(array_unique($requestIds));
    $priorIds = [];

    foreach ($case['calls'] as $call) {
        if (in_array($call['token'], $case['prior'], true)) {
            $priorIds[$call['id']] = true;
        }
    }

    $runs = [];
    $outcomes = [];
    $approvals = [];

    foreach ($case['calls'] as $call) {
        if (! g392pIsGated($call['tool'])) {
            continue;
        }

        if (isset($priorIds[$call['id']])) {
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
                return Http::response('data: '.json_encode(self::text('{"answer":"done"}')).'

', 200, ['Content-Type' => 'text/event-stream']);
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
function g392pResume(string $path, array $history, array &$ran): array
{
    $tools = g392pTools($ran);

    if ($path === 'stream') {
        $events = [];

        foreach (Prism::text()->using('gemini', 'test-model')->withTools($tools)->withMaxSteps(3)->withMessages($history)->asStream() as $event) {
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

        $results = [...$prior, ...array_map(fn (ToolResultEvent $event): ToolResult => $event->toolResult, $events)];
        $kept[] = new ToolResultMessage($results, $responses);
        $kept[] = new AssistantMessage('{"answer":"done"}');

        return ['results' => $results, 'history' => $kept, 'events' => $events];
    }

    $response = $path === 'structured'
        ? Prism::structured()->using('gemini', 'test-model')->withSchema(g392pSchema())->withTools($tools)->withMaxSteps(3)->withMessages($history)->asStructured()
        : Prism::text()->using('gemini', 'test-model')->withTools($tools)->withMaxSteps(3)->withMessages($history)->asText();

    $sent = $response->steps->first()->messages;
    $results = [];

    foreach (array_slice($sent, g392pLastToolStep($sent) + 1) as $message) {
        if ($message instanceof ToolResultMessage) {
            $results = [...$results, ...$message->toolResults];
        }
    }

    $kept = $path === 'text' ? $response->messages->all() : [...$sent, new AssistantMessage($response->text)];

    return ['results' => $results, 'history' => array_values($kept), 'events' => []];
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

    $history = [new UserMessage('Do the things'), new AssistantMessage('', $calls, [], $requests)];

    if ($case['split'] && $prior !== []) {
        $history[] = new ToolResultMessage($prior, []);
        $history[] = new ToolResultMessage([], $responses);
    } else {
        $history[] = new ToolResultMessage($prior, $responses);
    }

    return $history;
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

    $first = g392pResume($path, g392pHistory($case), $ran);
    $violation = g392pViolation($case, $expected, $ran, $first['results'], $first['events'], $path);

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
        g392pResume($path, [...$history, $extra], $ran);

        if ($ran !== $before) {
            return "invariant 3 (repeat resume on {$path}, {$case['repeat']}): ran again ".json_encode(array_slice($ran, count($before)));
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

        foreach (['calls', 'requests', 'responses'] as $key) {
            foreach (array_keys($case[$key]) as $i) {
                $candidate = $case;
                array_splice($candidate[$key], $i, 1);

                if ($key === 'calls') {
                    $candidate['prior'] = array_values(array_intersect($candidate['prior'], array_column($candidate['calls'], 'token')));
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
 * @param  callable(G392PropertyRng): array<string, mixed>  $generate
 * @param  callable(array<string, mixed>): ?string  $check
 * @param  list<string>  $mustSee  case kinds a run of 20+ examples must reach
 */
function g392pForAll(string $name, int $salt, callable $generate, callable $check, bool $shrinkable = true, array $mustSee = ['denied', 'prior', 'ran', 'repeated', 'undecided']): void
{
    $checked = 0;
    G392PropertyFake::$seen = [];
    $seeds = g392pSeeds($salt);

    foreach ($seeds as $seed) {
        $case = $generate(new G392PropertyRng($seed));
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

    if (count($seeds) >= 20) {
        $seen = array_keys(G392PropertyFake::$seen);
        sort($seen);

        expect($seen)->toBe($mustSee);
    }
}

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', 'not-a-key');
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
