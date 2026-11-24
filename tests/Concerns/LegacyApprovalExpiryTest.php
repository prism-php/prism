<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Streaming\StreamedMessages;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;

/*
 * Before 0.152, a call whose arguments could not be parsed was stored as one
 * string under `raw` or `input`. On a tool that really has a parameter with
 * that name, the stored call looks exactly like a genuine one. An approval
 * that does not say which version issued it is expired at resume in that
 * case, the call does not run, and the model is told to ask again.
 *
 * The rows under Fixtures/LegacyApprovals were written by the released
 * versions they name, through their public streaming API.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'fake');
    config()->set('prism.providers.openai.api_key', 'fake');
    config()->set('prism.providers.openai.api_format', 'chat_completions');
});

/**
 * @return array<string, mixed>
 */
function legacyApprovalRow(string $file): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/LegacyApprovals/'.$file), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The messages a consumer rebuilds from a stored row: what any consumer
 * that persisted toArray() before this change does.
 *
 * @param  array<string, mixed>  $row
 * @return array<int, mixed>
 */
function legacyApprovalHistory(array $row, bool $approved = true): array
{
    $calls = array_map(fn (array $call): ToolCall => new ToolCall($call['id'], $call['name'], $call['arguments']), $row['tool_calls']);
    $requests = array_map(fn (array $request): ToolApprovalRequest => new ToolApprovalRequest($request['approval_id'], $request['tool_call_id']), $row['tool_approval_requests']);

    return [
        new UserMessage('p'),
        new AssistantMessage($row['content'], $calls, [], $requests),
        new ToolResultMessage([], [new ToolApprovalResponse($row['tool_approval_requests'][0]['approval_id'], $approved)]),
    ];
}

function legacyApprovalTool(string $parameter, int &$runs): Tool
{
    return (new Tool)->as('probe')->for('probe')->withStringParameter($parameter, 'value')
        ->requiresApproval()
        ->using(function (string ...$args) use (&$runs): string {
            $runs++;

            return 'ran';
        });
}

function legacyChatText(string $text): array
{
    return ['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'model' => 'm', 'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
}

function legacyChatToolCall(string $arguments): array
{
    return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'm', 'content' => [['type' => 'tool_use', 'id' => 'call_2', 'name' => 'probe', 'input' => json_decode($arguments, true)]], 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
}

function legacyChatStreamText(string $text): string
{
    $line = fn (array $choice): string => 'data: '.json_encode(['id' => 'c1', 'model' => 'm', 'choices' => [$choice]])."\n\n";

    return $line(['index' => 0, 'delta' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => null])
        .$line(['index' => 0, 'delta' => (object) [], 'finish_reason' => 'stop'])."data: [DONE]\n\n";
}

/**
 * The body of a request the model was sent, as text.
 */
function legacySentBody(int $request = 0): string
{
    return (string) Http::recorded()[$request][0]->body();
}

/**
 * @return array<int, ToolApprovalRequest>
 */
function legacyPendingApprovals(TextResponse $response): array
{
    $assistant = collect($response->messages)->last(fn ($m): bool => $m instanceof AssistantMessage && $m->toolApprovalRequests !== []);

    return $assistant instanceof AssistantMessage ? $assistant->toolApprovalRequests : [];
}

dataset('rows written by released versions', [
    'v0.151.1, unparsed arguments under input' => ['v0.151.1-input.json', 'input'],
    'v0.151.1, unparsed arguments under raw' => ['v0.151.1-raw.json', 'raw'],
    'v0.152.0, a genuine input' => ['v0.152.0-input.json', 'input'],
]);

it('expires an approved call stored without a marker instead of running it', function (string $file, string $parameter): void {
    $row = legacyApprovalRow($file);
    $stored = $row['tool_calls'][0]['arguments'][$parameter];
    Http::fake(['*' => Http::response(legacyChatText('done'))]);
    Event::fake([ToolInvoked::class]);

    $runs = 0;
    $response = Prism::text()->using('anthropic', 'm')
        ->withMessages(legacyApprovalHistory($row))
        ->withTools([legacyApprovalTool($parameter, $runs)])
        ->withMaxSteps(3)
        ->asText();

    $result = collect($response->messages)->first(fn ($m): bool => $m instanceof ToolResultMessage && $m->toolResults !== [])?->toolResults[0];

    expect($runs)->toBe(0)
        ->and($result)->not->toBeNull()
        ->and($result->result)->toContain('expired')
        ->and($result->args)->toBe([])
        ->and(legacySentBody())->toContain('has expired')
        ->and(legacySentBody())->toContain('Call the tool again');

    // The result the model and the consumer see does not repeat the value.
    expect((string) json_encode($result->toArray()))->not->toContain(substr((string) json_encode($stored), 1, -1));
})->with('rows written by released versions');

it('expires it on the streaming resume, with an event that carries no arguments', function (string $file, string $parameter): void {
    $row = legacyApprovalRow($file);
    $stored = $row['tool_calls'][0]['arguments'][$parameter];
    Http::fake(['*' => Http::response(legacyChatStreamText('done'), 200, ['Content-Type' => 'text/event-stream'])]);

    $runs = 0;
    $results = [];
    foreach (Prism::text()->using('openai', 'm')
        ->withMessages(legacyApprovalHistory($row))
        ->withTools([legacyApprovalTool($parameter, $runs)])
        ->withMaxSteps(3)
        ->asStream() as $event) {
        if ($event instanceof ToolResultEvent) {
            $results[] = $event;
        }
    }

    expect($runs)->toBe(0)
        ->and($results)->toHaveCount(1)
        ->and($results[0]->success)->toBeFalse()
        ->and($results[0]->error)->toContain('expired')
        ->and($results[0]->toolResult->args)->toBe([])
        ->and(json_encode($results[0]->toArray()))->not->toContain(substr((string) json_encode($stored), 1, -1));
})->with('rows written by released versions');

it('does not run an expired call even when the model sends it again in the same words', function (): void {
    // The model is asked to call again. Its new call is a NEW pending
    // approval, issued with the marker, and still waits for the person.
    $row = legacyApprovalRow('v0.152.0-input.json');
    Http::fake(['*' => Http::sequence()
        ->push(legacyChatToolCall('{"input":"Paris"}'))
        ->push(legacyChatText('done'))]);

    $runs = 0;
    $response = Prism::text()->using('anthropic', 'm')
        ->withMessages(legacyApprovalHistory($row))
        ->withTools([legacyApprovalTool('input', $runs)])
        ->withMaxSteps(3)
        ->asText();

    $pending = legacyPendingApprovals($response);

    expect($runs)->toBe(0)
        ->and($pending)->toHaveCount(1)
        ->and($pending[0]->toolCallId)->toBe('call_2')
        ->and($pending[0]->toArray())->toHaveKey('arguments_format');
});

it('records the expiry in telemetry without the arguments', function (): void {
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', true);
    $row = legacyApprovalRow('v0.151.1-input.json');
    Http::fake(['*' => Http::response(legacyChatText('done'))]);
    Event::fake([ToolInvoked::class]);

    $runs = 0;
    Prism::text()->using('anthropic', 'm')
        ->withMessages(legacyApprovalHistory($row))
        ->withTools([legacyApprovalTool('input', $runs)])
        ->withMaxSteps(3)
        ->asText();

    $invoked = Event::dispatched(ToolInvoked::class)->map(fn (array $args): ToolInvoked => $args[0]);

    expect($runs)->toBe(0)
        ->and($invoked)->toHaveCount(1)
        ->and($invoked->first()->failed)->toBeTrue()
        ->and($invoked->first()->failure)->toBe('approval_expired')
        ->and($invoked->first()->toolCall?->arguments() ?? [])->toBe([])
        ->and($invoked->first()->toolResult?->args ?? [])->toBe([]);
});

it('marks every approval it issues on the text path', function (): void {
    Http::fake(['*' => Http::response(legacyChatToolCall('{"input":"Paris"}'))]);
    $runs = 0;

    $text = Prism::text()->using('anthropic', 'm')->withPrompt('p')
        ->withTools([legacyApprovalTool('input', $runs)])->withMaxSteps(3)->asText();

    expect($runs)->toBe(0)
        ->and(legacyPendingApprovals($text))->toHaveCount(1)
        ->and(legacyPendingApprovals($text)[0]->toArray())->toHaveKey('arguments_format');
});

it('marks every approval it issues on the streaming path', function (): void {
    $runs = 0;
    $line = fn (array $choice): string => 'data: '.json_encode(['id' => 'c1', 'model' => 'm', 'choices' => [$choice]])."\n\n";
    Http::fake(['*' => Http::response(
        $line(['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_3', 'type' => 'function', 'function' => ['name' => 'probe', 'arguments' => '{"input":"Paris"}']]]], 'finish_reason' => null])
        .$line(['index' => 0, 'delta' => (object) [], 'finish_reason' => 'tool_calls'])."data: [DONE]\n\n",
        200,
        ['Content-Type' => 'text/event-stream'],
    )]);

    $streamed = StreamedMessages::fromEvents(Prism::text()->using('openai', 'm')->withPrompt('p')
        ->withTools([legacyApprovalTool('input', $runs)])->withMaxSteps(3)->asStream());
    $assistant = $streamed->first(fn ($m): bool => $m instanceof AssistantMessage && $m->toolApprovalRequests !== []);

    expect($runs)->toBe(0)
        ->and($assistant)->not->toBeNull()
        ->and($assistant?->toolApprovalRequests[0]->toArray() ?? [])->toHaveKey('arguments_format');
});

it('runs a marked approval after it is stored and read back', function (string $parameter): void {
    expect(method_exists(ToolApprovalRequest::class, 'fromArray'))->toBeTrue();

    // Issue a real pending approval, store it as JSON, read it back.
    Http::fake(['*' => Http::sequence()
        ->push(legacyChatToolCall(json_encode([$parameter => 'Paris'])))
        ->push(legacyChatText('done'))]);
    $runs = 0;
    $tool = legacyApprovalTool($parameter, $runs);

    /** @var TextResponse $first */
    $first = Prism::text()->using('anthropic', 'm')->withPrompt('p')->withTools([$tool])->withMaxSteps(3)->asText();
    $stored = json_decode((string) json_encode(legacyPendingApprovals($first)[0]->toArray()), true);
    $request = ToolApprovalRequest::fromArray($stored);

    Prism::text()->using('anthropic', 'm')
        ->withMessages([
            new UserMessage('p'),
            new AssistantMessage('', [new ToolCall('call_2', 'probe', [$parameter => 'Paris'])], [], [$request]),
            new ToolResultMessage([], [new ToolApprovalResponse($request->approvalId, true)]),
        ])
        ->withTools([$tool])->withMaxSteps(3)->asText();

    expect($runs)->toBe(1);
})->with(['input', 'raw']);

it('reads a marker it does not recognise as no marker', function (mixed $format): void {
    expect(method_exists(ToolApprovalRequest::class, 'fromArray'))->toBeTrue();

    $row = legacyApprovalRow('v0.152.0-input.json');
    $request = ToolApprovalRequest::fromArray([...$row['tool_approval_requests'][0], 'arguments_format' => $format]);
    Http::fake(['*' => Http::response(legacyChatText('done'))]);

    $runs = 0;
    Prism::text()->using('anthropic', 'm')
        ->withMessages([
            new UserMessage('p'),
            new AssistantMessage('', [new ToolCall('toolu_1', 'probe', ['input' => 'Paris'])], [], [$request]),
            new ToolResultMessage([], [new ToolApprovalResponse($request->approvalId, true)]),
        ])
        ->withTools([legacyApprovalTool('input', $runs)])->withMaxSteps(3)->asText();

    expect($runs)->toBe(0);
})->with([
    'a string' => ['1'],
    'zero' => [0],
    'a future version' => [99],
    'true' => [true],
    'null' => [null],
]);

it('still runs an unmarked approved call that is not ambiguous', function (array $arguments): void {
    // Only one string under `raw` or `input` is ambiguous. Anything else an
    // older version stored is exactly what the model sent.
    $tool = (new Tool)->as('probe')->for('probe')
        ->withStringParameter('input', 'value', false)->withStringParameter('city', 'city', false)
        ->requiresApproval()
        ->using(function (string ...$args) use (&$runs): string {
            $runs++;

            return 'ran';
        });
    Http::fake(['*' => Http::response(legacyChatText('done'))]);

    $runs = 0;
    Prism::text()->using('anthropic', 'm')
        ->withMessages([
            new UserMessage('p'),
            new AssistantMessage('', [new ToolCall('call_1', 'probe', $arguments)], [], [new ToolApprovalRequest('apr_1', 'call_1')]),
            new ToolResultMessage([], [new ToolApprovalResponse('apr_1', true)]),
        ])
        ->withTools([$tool])->withMaxSteps(3)->asText();

    expect($runs)->toBe(1);
})->with([
    'another parameter' => [['city' => 'Paris']],
    'two keys' => [['input' => 'Paris', 'city' => 'Paris']],
    'no arguments' => [[]],
]);

it('leaves a denied legacy approval denied', function (): void {
    $row = legacyApprovalRow('v0.151.1-input.json');
    Http::fake(['*' => Http::response(legacyChatText('done'))]);

    $runs = 0;
    $response = Prism::text()->using('anthropic', 'm')
        ->withMessages(legacyApprovalHistory($row, approved: false))
        ->withTools([legacyApprovalTool('input', $runs)])
        ->withMaxSteps(3)
        ->asText();

    $result = collect($response->messages)->first(fn ($m): bool => $m instanceof ToolResultMessage && $m->toolResults !== [])?->toolResults[0];

    expect($runs)->toBe(0)->and($result?->result)->toBe('User denied tool execution');
});

it('does not mark a request rebuilt from a stored event, so the legacy call still expires', function (): void {
    // An app that kept the approval events from before the upgrade and
    // rebuilds them afterwards. The rebuilt event was not issued by this
    // version, so neither it nor the messages built from it may carry the
    // marker.
    $row = legacyApprovalRow('v0.151.1-input.json');
    $call = new ToolCall($row['tool_calls'][0]['id'], $row['tool_calls'][0]['name'], $row['tool_calls'][0]['arguments']);
    $event = new ToolApprovalRequestEvent(
        id: 'evt_1',
        timestamp: 1,
        toolCall: $call,
        messageId: 'msg_1',
        approvalId: $row['tool_approval_requests'][0]['approval_id'],
    );

    $assistant = StreamedMessages::fromEvents([$event])
        ->first(fn ($m): bool => $m instanceof AssistantMessage && $m->toolApprovalRequests !== []);

    expect($event->approvalRequest()->toArray())->not->toHaveKey('arguments_format')
        ->and($assistant)->not->toBeNull()
        ->and($assistant?->toolApprovalRequests[0]->toArray() ?? [])->not->toHaveKey('arguments_format');

    Http::fake(['*' => Http::response(legacyChatText('done'))]);
    $runs = 0;
    Prism::text()->using('anthropic', 'm')
        ->withMessages([
            new UserMessage('p'),
            new AssistantMessage('', [$call], [], $assistant?->toolApprovalRequests ?? []),
            new ToolResultMessage([], [new ToolApprovalResponse($row['tool_approval_requests'][0]['approval_id'], true)]),
        ])
        ->withTools([legacyApprovalTool('input', $runs)])
        ->withMaxSteps(3)
        ->asText();

    expect($runs)->toBe(0)
        ->and(legacySentBody())->toContain('has expired');
});
