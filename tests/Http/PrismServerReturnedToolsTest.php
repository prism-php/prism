<?php

declare(strict_types=1);

namespace Tests\Http;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\PrismServer;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

/**
 * A client-executed tool with a handler anyway. If anything runs it, the call is recorded and it throws.
 */
class RecordingReturnedTool extends Tool
{
    public static int $calls = 0;

    public function __construct()
    {
        parent::__construct();

        $this->as('business_case_report')
            ->for('Call once the interview is over, with what was captured')
            ->withStringParameter('problem', 'The problem')
            ->withStringParameter('report', 'The report')
            ->clientExecuted();
    }

    public function __invoke(string $problem = '', string $report = ''): string
    {
        self::$calls++;

        throw new RuntimeException('A returned tool must never be executed by the server');
    }
}

function serverLookupTool(array &$calls): Tool
{
    return (new Tool)
        ->as('lookup')
        ->for('Look something up')
        ->withStringParameter('query', 'The query')
        ->using(function (string $query) use (&$calls): string {
            $calls[] = $query;

            return "found {$query}";
        });
}

/**
 * @param  array<int, array<string, mixed>>  $output
 * @return array<string, mixed>
 */
function openAiResponse(string $id, array $output, int $input = 20, int $outputTokens = 10): array
{
    return [
        'id' => $id,
        'object' => 'response',
        'created_at' => 1741989983,
        'status' => 'completed',
        'model' => 'gpt-4o-2024-08-06',
        'output' => $output,
        'usage' => [
            'input_tokens' => $input,
            'input_tokens_details' => ['cached_tokens' => 0],
            'output_tokens' => $outputTokens,
            'output_tokens_details' => ['reasoning_tokens' => 0],
            'total_tokens' => $input + $outputTokens,
        ],
    ];
}

/** @return array<string, mixed> */
function openAiFunctionCall(string $callId, string $name, string $arguments): array
{
    return [
        'id' => 'fc_'.$callId,
        'type' => 'function_call',
        'status' => 'completed',
        'arguments' => $arguments,
        'call_id' => $callId,
        'name' => $name,
    ];
}

/** @return array<string, mixed> */
function openAiText(string $text): array
{
    return [
        'id' => 'msg_1',
        'type' => 'message',
        'status' => 'completed',
        'role' => 'assistant',
        'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]],
    ];
}

function registerInterview(Tool ...$extra): void
{
    PrismServer::register('interview', fn (): PendingRequest => Prism::text()
        ->using('openai', 'gpt-4o')
        ->withSystemPrompt('Interview the user.')
        ->withMaxSteps(5)
        ->withTools([...$extra, new RecordingReturnedTool]));
}

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.providers.anthropic.api_key', 'sk-ant-test');
    RecordingReturnedTool::$calls = 0;
    Http::preventStrayRequests();
});

it('returns a call to a returned tool in the OpenAI shape and never runs it', function (): void {
    $this->freezeTime();

    Http::fakeSequence('api.openai.com/v1/responses')
        ->push(openAiResponse('resp_end', [
            openAiText('Thanks, that is everything.'),
            openAiFunctionCall('call_abc123', 'business_case_report', '{"problem":"churn","report":"…"}'),
        ]))
        ->whenEmpty(Http::response(['error' => 'the loop went on past a returned tool'], 500));

    registerInterview();

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => [['role' => 'user', 'content' => 'That is all from me.']],
    ]);

    $response->assertOk();

    $json = $response->json();
    expect($json['id'])->toMatch('/^chatcmpl-[A-Za-z0-9]{29}$/')
        ->and($json['choices'][0]['message']['tool_calls'][0]['id'])->toMatch('/^call_[A-Za-z0-9]{24}$/');
    unset($json['id'], $json['choices'][0]['message']['tool_calls'][0]['id']);

    expect($json)->toBe([
        'object' => 'chat.completion',
        'created' => now()->timestamp,
        'model' => 'interview',
        'usage' => [
            'prompt_tokens' => 20,
            'completion_tokens' => 10,
            'total_tokens' => 30,
            'prompt_tokens_details' => ['cached_tokens' => 0],
            'completion_tokens_details' => ['reasoning_tokens' => 0],
        ],
        'choices' => [[
            'index' => 0,
            'message' => [
                'content' => 'Thanks, that is everything.',
                'role' => 'assistant',
                'tool_calls' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => 'business_case_report',
                        'arguments' => '{"problem":"churn","report":"…"}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
    ])
        ->and(RecordingReturnedTool::$calls)->toBe(0);

    // The arguments are a JSON STRING on the wire, not an object.
    expect(json_decode((string) $response->getContent(), true)['choices'][0]['message']['tool_calls'][0]['function']['arguments'])
        ->toBeString();

    // One provider call: the loop stopped at the returned tool.
    Http::assertSentCount(1);
});

it('sends content null when the model wrote no text beside the call', function (): void {
    Http::fake(['api.openai.com/v1/responses' => Http::response(openAiResponse('resp_1', [
        openAiFunctionCall('call_1', 'business_case_report', '{"problem":"p","report":"r"}'),
    ]))]);

    registerInterview();

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => [['role' => 'user', 'content' => 'Done.']],
    ]);

    $response->assertOk();
    $message = json_decode((string) $response->getContent(), true)['choices'][0]['message'];

    expect($message)->toHaveKey('content')
        ->and($message['content'])->toBeNull()
        ->and(RecordingReturnedTool::$calls)->toBe(0);
});

it('serialises empty arguments as the string "{}" when the provider sent a string', function (): void {
    Http::fake(['api.openai.com/v1/responses' => Http::response(openAiResponse('resp_1', [
        openAiFunctionCall('call_1', 'business_case_report', '{}'),
    ]))]);

    registerInterview();

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => [['role' => 'user', 'content' => 'Done.']],
    ]);

    expect(json_decode((string) $response->getContent(), true)['choices'][0]['message']['tool_calls'][0]['function']['arguments'])
        ->toBe('{}');
    expect((string) $response->getContent())->toContain('"arguments":"{}"');
});

it('serialises empty arguments as "{}", never "[]", when the provider decoded them itself', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response([
        'id' => 'msg_01',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-5-5',
        'content' => [
            ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'finish', 'input' => new \stdClass],
        ],
        'stop_reason' => 'tool_use',
        'stop_sequence' => null,
        'usage' => ['input_tokens' => 12, 'output_tokens' => 3],
    ])]);

    PrismServer::register('claude', fn (): PendingRequest => Prism::text()
        ->using('anthropic', 'claude-sonnet-5-5')
        ->withTools([(new Tool)->as('finish')->for('End the conversation')->clientExecuted()]));

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'claude',
        'messages' => [['role' => 'user', 'content' => 'Bye.']],
    ]);

    $response->assertOk();
    $call = $response->json('choices.0.message.tool_calls.0');
    expect($call['id'])->toMatch('/^call_[A-Za-z0-9]{24}$/');
    unset($call['id']);
    expect($call)->toBe([
        'type' => 'function',
        'function' => ['name' => 'finish', 'arguments' => '{}'],
    ])
        ->and($response->json('choices.0.message.content'))->toBeNull()
        ->and($response->json('choices.0.finish_reason'))->toBe('tool_calls');
});

it('runs server tools in earlier steps, then stops at the returned tool with the whole turn\'s usage', function (): void {
    $lookups = [];

    Http::fakeSequence('api.openai.com/v1/responses')
        ->push(openAiResponse('resp_1', [openAiFunctionCall('call_l', 'lookup', '{"query":"pricing"}')], 20, 5))
        ->push(openAiResponse('resp_2', [openAiFunctionCall('call_r', 'business_case_report', '{"problem":"p","report":"r"}')], 40, 8))
        ->whenEmpty(Http::response(['error' => 'the loop went on past a returned tool'], 500));

    registerInterview(serverLookupTool($lookups));

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => [['role' => 'user', 'content' => 'Done.']],
    ]);

    $response->assertOk();
    expect($lookups)->toBe(['pricing'])
        ->and(RecordingReturnedTool::$calls)->toBe(0)
        ->and($response->json('choices.0.message.tool_calls'))->toHaveCount(1)
        ->and($response->json('choices.0.message.tool_calls.0.function.name'))->toBe('business_case_report')
        ->and($response->json('usage.prompt_tokens'))->toBe(60)
        ->and($response->json('usage.completion_tokens'))->toBe(13);

    Http::assertSentCount(2);
});

it('runs nothing from a step that calls both a server tool and a returned tool, and exposes only the returned call', function (): void {
    $lookups = [];

    Http::fakeSequence('api.openai.com/v1/responses')
        ->push(openAiResponse('resp_mixed', [
            openAiFunctionCall('call_l', 'lookup', '{"query":"secret"}'),
            openAiFunctionCall('call_r', 'business_case_report', '{"problem":"p","report":"r"}'),
        ]))
        ->whenEmpty(Http::response(['error' => 'the loop went on past a returned tool'], 500));

    $approval = (new Tool)->as('refund')->for('Refund')->withStringParameter('id', 'Id')
        ->using(fn (string $id): string => throw new RuntimeException('approval tool ran'))
        ->requiresApproval();

    registerInterview(serverLookupTool($lookups), $approval);

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => [['role' => 'user', 'content' => 'Done.']],
    ]);

    $response->assertOk();
    expect($lookups)->toBe([])
        ->and(RecordingReturnedTool::$calls)->toBe(0)
        ->and($response->json('choices.0.finish_reason'))->toBe('tool_calls')
        ->and($response->json('choices.0.message.tool_calls'))->toHaveCount(1)
        ->and($response->json('choices.0.message.tool_calls.0.function.name'))->toBe('business_case_report')
        ->and((string) $response->getContent())->not->toContain('lookup')
        ->and((string) $response->getContent())->not->toContain('secret');

    Http::assertSentCount(1);
});

it('leaves the app\'s own Tool instances unmarked', function (): void {
    Http::fake(['api.openai.com/v1/responses' => Http::response(openAiResponse('resp_1', [
        openAiFunctionCall('call_1', 'business_case_report', '{}'),
    ]))]);

    $returned = (new Tool)->as('business_case_report')->for('Report')->clientExecuted();

    PrismServer::register('interview', fn (): PendingRequest => Prism::text()
        ->using('openai', 'gpt-4o')
        ->withTools([$returned]));

    $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => [['role' => 'user', 'content' => 'Done.']],
    ])->assertOk();

    expect($returned->withholdsOtherCalls())->toBeFalse();
});

it('does not change a mixed step outside Prism Server: the server tool still runs', function (): void {
    $lookups = [];

    Http::fake(['api.openai.com/v1/responses' => Http::response(openAiResponse('resp_mixed', [
        openAiFunctionCall('call_l', 'lookup', '{"query":"x"}'),
        openAiFunctionCall('call_r', 'business_case_report', '{}'),
    ]))]);

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Done.')
        ->withMaxSteps(5)
        ->withTools([serverLookupTool($lookups), (new Tool)->as('business_case_report')->for('Report')->clientExecuted()])
        ->asText();

    expect($lookups)->toBe(['x'])
        ->and($response->finishReason)->toBe(FinishReason::ToolCalls);
});

it('never offers the provider tools or a tool_choice the client sent', function (): void {
    Http::fake(['api.openai.com/v1/responses' => Http::response(openAiResponse('resp_1', [openAiText('Next question?')]))]);

    registerInterview();

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => [['role' => 'user', 'content' => 'Hi']],
        'tools' => [
            ['type' => 'function', 'function' => ['name' => 'exfiltrate', 'description' => 'x', 'parameters' => ['type' => 'object']]],
            ['type' => 'function', 'function' => ['name' => 'business_case_report', 'description' => 'redefined', 'parameters' => ['type' => 'object', 'properties' => ['stolen' => ['type' => 'string']]]]],
        ],
        'tool_choice' => ['type' => 'function', 'function' => ['name' => 'exfiltrate']],
    ]);

    $response->assertOk();
    expect($response->json('choices.0.finish_reason'))->toBe('stop')
        ->and($response->json('choices.0.message'))->toBe(['content' => 'Next question?', 'role' => 'assistant']);

    Http::assertSent(function (Request $request): bool {
        $tools = $request->data()['tools'] ?? [];

        expect(array_column($tools, 'name'))->toBe(['business_case_report'])
            ->and($tools[0]['description'])->toBe('Call once the interview is over, with what was captured')
            ->and(array_keys((array) $tools[0]['parameters']['properties']))->toBe(['problem', 'report'])
            ->and($request->data())->not->toHaveKey('tool_choice')
            ->and((string) $request->body())->not->toContain('exfiltrate')
            ->and((string) $request->body())->not->toContain('stolen');

        return true;
    });
});

it('does not return a call the model made to a tool the client named but the app never registered', function (): void {
    Http::fake(['api.openai.com/v1/responses' => Http::response(openAiResponse('resp_1', [
        openAiFunctionCall('call_x', 'exfiltrate', '{}'),
    ]))]);

    registerInterview();

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => [['role' => 'user', 'content' => 'Hi']],
        'tools' => [['type' => 'function', 'function' => ['name' => 'exfiltrate']]],
    ]);

    expect($response->json('choices.0.message'))->not->toHaveKey('tool_calls')
        ->and((string) $response->getContent())->not->toContain('exfiltrate');
});

it('refuses a tool message with the generic 422 invalid_messages', function (): void {
    Http::fake();

    registerInterview();

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => [
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"ok":true}'],
        ],
    ]);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe([
        'message' => 'The messages could not be read.',
        'type' => 'invalid_request_error',
        'code' => 'invalid_messages',
    ])
        ->and(RecordingReturnedTool::$calls)->toBe(0);

    Http::assertNothingSent();
});

it('refuses assistant tool calls in the history with 422 invalid_messages naming the message', function (array $message): void {
    Http::fake();

    registerInterview();

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => [['role' => 'user', 'content' => 'Hi'], $message],
    ]);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe([
        'message' => 'messages[1]: an assistant message carrying tool calls cannot be replayed; a returned tool call ends the conversation.',
        'type' => 'invalid_request_error',
        'code' => 'invalid_messages',
    ])
        ->and(RecordingReturnedTool::$calls)->toBe(0);

    Http::assertNothingSent();
})->with([
    'assistant tool call' => [['role' => 'assistant', 'content' => null, 'tool_calls' => [[
        'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'business_case_report', 'arguments' => '{}'],
    ]]]],
    'assistant tool call with text' => [['role' => 'assistant', 'content' => 'ok', 'tool_calls' => [[
        'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{}'],
    ]]]],
    'legacy function call' => [['role' => 'assistant', 'content' => null, 'function_call' => ['name' => 'lookup', 'arguments' => '{}']]],
]);

it('still accepts a plain assistant message, and one with an empty tool_calls list', function (): void {
    Http::fake(['api.openai.com/v1/responses' => Http::response(openAiResponse('resp_1', [openAiText('Next?')]))]);

    registerInterview();

    $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => [
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'assistant', 'content' => 'First question?', 'tool_calls' => []],
            ['role' => 'user', 'content' => 'Answer'],
        ],
    ])->assertOk();
});

it('refuses to stream a prism that returns tools, before any provider call', function (): void {
    Http::fake();

    registerInterview();

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'stream' => true,
        'messages' => [['role' => 'user', 'content' => 'Done.']],
    ]);

    $response->assertStatus(400);
    expect($response->json('error'))->toBe([
        'message' => 'This model does not support streaming. Send the request with "stream": false.',
        'type' => 'invalid_request_error',
        'code' => 'stream_not_supported',
    ])
        ->and(RecordingReturnedTool::$calls)->toBe(0);

    Http::assertNothingSent();
});

it('keeps provider failures behind the safe error response', function (): void {
    Exceptions::fake();

    Http::fake(['api.openai.com/v1/responses' => Http::response([
        'error' => ['message' => 'Incorrect API key provided: sk-live-SECRET', 'type' => 'invalid_request_error'],
    ], 401)]);

    registerInterview();

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => [['role' => 'user', 'content' => 'Done.']],
    ]);

    $response->assertStatus(500);
    expect($response->json())->toBe(['error' => [
        'message' => 'The server could not complete the request.',
        'type' => 'server_error',
        'code' => null,
    ]])
        ->and((string) $response->getContent())->not->toContain('SECRET')
        ->and((string) $response->getContent())->not->toContain('business_case_report');
});

it('generates a tool call id when the provider gave none', function (): void {
    $generator = Mockery::mock(PendingRequest::class);
    $generator->allows('withMessages')->andReturnSelf();
    $generator->allows('tools')->andReturn([(new Tool)->as('finish')->for('Finish')->clientExecuted()]);
    $generator->allows('withTools')->andReturnSelf();
    $generator->expects('asText')->andReturn(new Response(
        steps: collect(),
        text: '',
        finishReason: FinishReason::ToolCalls,
        toolCalls: [new ToolCall(id: '', name: 'finish', arguments: [])],
        toolResults: [],
        usage: new Usage(1, 1),
        meta: new Meta('gen_1', 'gemini'),
        messages: collect(),
    ));

    PrismServer::register('gem', fn (): PendingRequest => $generator);

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'gem',
        'messages' => [['role' => 'user', 'content' => 'Bye']],
    ]);

    expect($response->json('choices.0.message.tool_calls.0.id'))->toStartWith('call_')
        ->and(strlen((string) $response->json('choices.0.message.tool_calls.0.id')))->toBeGreaterThan(5)
        ->and($response->json('choices.0.message.tool_calls.0.function.arguments'))->toBe('{}');
});

it('never returns a call to a tool whose name a server tool shares', function (): void {
    $lookups = [];

    Http::fake(['api.openai.com/v1/responses' => Http::response(openAiResponse('resp_1', [
        openAiFunctionCall('call_1', 'lookup', '{"query":"q"}'),
    ]))]);

    PrismServer::register('dup', fn (): PendingRequest => Prism::text()
        ->using('openai', 'gpt-4o')
        ->withTools([serverLookupTool($lookups), (new Tool)->as('lookup')->for('Shadow')->clientExecuted()]));

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'dup',
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ]);

    expect($response->json('choices.0.message'))->not->toHaveKey('tool_calls');
});

it('only withholds a step for a client-executed tool', function (): void {
    expect((new Tool)->as('a')->for('a')->using(fn (): string => '')->withholdOtherCalls()->withholdsOtherCalls())->toBeFalse()
        ->and((new Tool)->as('b')->for('b')->clientExecuted()->withholdsOtherCalls())->toBeFalse()
        ->and((new Tool)->as('c')->for('c')->clientExecuted()->withholdOtherCalls()->withholdsOtherCalls())->toBeTrue();
});

it('names the position of a replayed tool call in the client\'s own messages array', function (): void {
    Http::fake();

    registerInterview();

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => [
            ['role' => 'system', 'content' => 'Be brief.'],
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'assistant', 'content' => 'First question?'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'business_case_report', 'arguments' => '{}'],
            ]]],
            ['role' => 'user', 'content' => 'Again'],
        ],
    ]);

    $response->assertStatus(422);
    expect($response->json())->toBe(['error' => [
        'message' => 'messages[3]: an assistant message carrying tool calls cannot be replayed; a returned tool call ends the conversation.',
        'type' => 'invalid_request_error',
        'code' => 'invalid_messages',
    ]]);

    Http::assertNothingSent();
});

it('keeps the generic message for every other unreadable message', function (array $messages): void {
    Http::fake();

    registerInterview();

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => $messages,
    ]);

    $response->assertStatus(422);
    expect($response->json('error.message'))->toBe('The messages could not be read.')
        ->and($response->json('error.code'))->toBe('invalid_messages');
})->with([
    'unknown role' => [[['role' => 'user', 'content' => 'Hi'], ['role' => 'developer', 'content' => 'x']]],
    'function role' => [[['role' => 'function', 'name' => 'lookup', 'content' => 'x']]],
]);

it('carries both the text and the tool call when the final step has both', function (): void {
    Http::fake(['api.openai.com/v1/responses' => Http::response(openAiResponse('resp_both', [
        openAiText('Here is your report.'),
        openAiFunctionCall('call_both', 'business_case_report', '{"problem":"p","report":"r"}'),
    ]))]);

    registerInterview();

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => [['role' => 'user', 'content' => 'Done.']],
    ]);

    $response->assertOk();
    $choice = $response->json('choices.0');
    expect($choice['message']['tool_calls'][0]['id'])->toMatch('/^call_[A-Za-z0-9]{24}$/');
    unset($choice['message']['tool_calls'][0]['id']);
    expect($choice)->toBe([
        'index' => 0,
        'message' => [
            'content' => 'Here is your report.',
            'role' => 'assistant',
            'tool_calls' => [[
                'type' => 'function',
                'function' => ['name' => 'business_case_report', 'arguments' => '{"problem":"p","report":"r"}'],
            ]],
        ],
        'finish_reason' => 'tool_calls',
    ])
        ->and(RecordingReturnedTool::$calls)->toBe(0);
});

it('ignores unknown top-level request fields rather than refusing them', function (): void {
    Http::fake(['api.openai.com/v1/responses' => Http::response(openAiResponse('resp_1', [openAiText('Next question?')]))]);

    registerInterview();

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'interview',
        'messages' => [['role' => 'user', 'content' => 'Hi']],
        'skill' => 'x',
        'temperature' => 2,
        'metadata' => ['anything' => true],
    ]);

    $response->assertOk();
    expect($response->json('choices.0.message.content'))->toBe('Next question?')
        ->and($response->json('choices.0.finish_reason'))->toBe('stop');

    Http::assertSent(function (Request $request): bool {
        expect((string) $request->body())->not->toContain('skill')
            ->and($request->data())->not->toHaveKey('metadata');

        return true;
    });
});
