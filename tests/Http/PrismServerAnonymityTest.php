<?php

declare(strict_types=1);

namespace Tests\Http;

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
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

/*
 * G-315: a Prism Server response reveals nothing about the provider or model behind a registered prism.
 *
 * Every fake below answers with distinctive provider values. Each test serialises the WHOLE body (JSON or
 * SSE) and the headers and searches them, so a provider field that reaches the caller by any route fails,
 * not only the fields a test happens to look at.
 */

const ANON_OPENAI_REQUESTED_MODEL = 'gpt-leak-requested-7731';
const ANON_ANTHROPIC_REQUESTED_MODEL = 'claude-leak-requested-7731';

/** @return array<int, string> */
function anonProviderStrings(): array
{
    return [
        // Every provider value the fakes send carries LEAK; underscores and hyphens never occur in a generated id.
        'LEAK',
        ANON_OPENAI_REQUESTED_MODEL,
        ANON_ANTHROPIC_REQUESTED_MODEL,
        'gpt-',
        'claude',
        'openai',
        'anthropic',
        'toolu_',
        'resp_',
        'msg_',
        'fc_',
        'fp_',
        'system_fingerprint',
        'service_tier',
    ];
}

function expectAnonymous(TestResponse $response, string $body): void
{
    $headers = (string) $response->baseResponse->headers;

    foreach (anonProviderStrings() as $needle) {
        expect(stripos($body, $needle))->toBeFalse("The body reveals the provider: found \"{$needle}\" in {$body}")
            ->and(stripos($headers, $needle))->toBeFalse("The headers reveal the provider: found \"{$needle}\"");
    }
}

/** @param array<string, mixed> $usage */
function expectOpenAiUsageOnly(array $usage): void
{
    expect(array_diff(array_keys($usage), [
        'prompt_tokens', 'completion_tokens', 'total_tokens', 'prompt_tokens_details', 'completion_tokens_details',
    ]))->toBe([]);
}

/**
 * @param  array<int, array<string, mixed>>  $output
 * @return array<string, mixed>
 */
function anonOpenAiResponse(array $output): array
{
    return [
        'id' => 'resp_LEAKresp7731',
        'object' => 'response',
        'created_at' => 1741989983,
        'status' => 'completed',
        'model' => 'gpt-LEAK-served-7731',
        'service_tier' => 'LEAKtier',
        'system_fingerprint' => 'fp_LEAKfp7731',
        'output' => $output,
        'usage' => [
            'input_tokens' => 20,
            'input_tokens_details' => ['cached_tokens' => 0],
            'output_tokens' => 10,
            'output_tokens_details' => ['reasoning_tokens' => 0],
            'total_tokens' => 30,
        ],
    ];
}

/** @return array<string, mixed> */
function anonOpenAiText(string $text): array
{
    return [
        'id' => 'msg_LEAKmsg7731',
        'type' => 'message',
        'status' => 'completed',
        'role' => 'assistant',
        'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]],
    ];
}

/** @return array<string, mixed> */
function anonOpenAiCall(string $callId, string $arguments = '{}'): array
{
    return [
        'id' => 'fc_LEAK'.$callId,
        'type' => 'function_call',
        'status' => 'completed',
        'arguments' => $arguments,
        'call_id' => 'call_LEAK'.$callId,
        'name' => 'hand_back',
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $content
 * @return array<string, mixed>
 */
function anonAnthropicResponse(array $content, string $stopReason = 'end_turn'): array
{
    return [
        'id' => 'msg_LEAKanthropic7731',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-LEAK-served-7731',
        'content' => $content,
        'stop_reason' => $stopReason,
        'stop_sequence' => null,
        'usage' => [
            'input_tokens' => 12,
            'output_tokens' => 3,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'service_tier' => 'LEAKtier',
        ],
    ];
}

/** @param array<int, array<string, mixed>> $events */
function anonSse(array $events): string
{
    return collect($events)
        ->map(fn (array $event): string => "event: {$event['type']}\ndata: ".json_encode($event)."\n\n")
        ->implode('');
}

function anonOpenAiStream(): string
{
    $response = ['id' => 'resp_LEAKresp7731', 'object' => 'response', 'created_at' => 1750705325, 'status' => 'in_progress', 'model' => 'gpt-LEAK-served-7731', 'output' => [], 'usage' => null];
    $item = 'msg_LEAKmsg7731';

    return anonSse([
        ['type' => 'response.created', 'sequence_number' => 0, 'response' => $response],
        ['type' => 'response.output_item.added', 'sequence_number' => 1, 'output_index' => 0, 'item' => ['id' => $item, 'type' => 'message', 'status' => 'in_progress', 'content' => [], 'role' => 'assistant']],
        ['type' => 'response.content_part.added', 'sequence_number' => 2, 'item_id' => $item, 'output_index' => 0, 'content_index' => 0, 'part' => ['type' => 'output_text', 'annotations' => [], 'text' => '']],
        ['type' => 'response.output_text.delta', 'sequence_number' => 3, 'item_id' => $item, 'output_index' => 0, 'content_index' => 0, 'delta' => 'Hello'],
        ['type' => 'response.output_text.delta', 'sequence_number' => 4, 'item_id' => $item, 'output_index' => 0, 'content_index' => 0, 'delta' => ' there'],
        ['type' => 'response.output_text.delta', 'sequence_number' => 5, 'item_id' => $item, 'output_index' => 0, 'content_index' => 0, 'delta' => '.'],
        ['type' => 'response.output_text.done', 'sequence_number' => 6, 'item_id' => $item, 'output_index' => 0, 'content_index' => 0, 'text' => 'Hello there.'],
        ['type' => 'response.output_item.done', 'sequence_number' => 7, 'output_index' => 0, 'item' => anonOpenAiText('Hello there.')],
        ['type' => 'response.completed', 'sequence_number' => 8, 'response' => anonOpenAiResponse([anonOpenAiText('Hello there.')])],
    ]);
}

function anonAnthropicStream(): string
{
    return anonSse([
        ['type' => 'message_start', 'message' => ['id' => 'msg_LEAKanthropic7731', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-LEAK-served-7731', 'content' => [], 'usage' => ['input_tokens' => 11, 'output_tokens' => 1]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => ' there']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => '.']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 4]],
        ['type' => 'message_stop'],
    ]);
}

function anonReturnedTool(): Tool
{
    return (new Tool)->as('hand_back')->for('Hand the conversation back')->clientExecuted();
}

function registerAnon(string $provider, bool $withTool = false): void
{
    $model = $provider === 'openai' ? ANON_OPENAI_REQUESTED_MODEL : ANON_ANTHROPIC_REQUESTED_MODEL;

    PrismServer::register('team-brain', function () use ($provider, $model, $withTool): PendingRequest {
        $prism = Prism::text()->using($provider, $model);

        return $withTool ? $prism->withTools([anonReturnedTool()]) : $prism;
    });
}

/**
 * @return array<int, array<string, mixed>>
 */
function anonChunks(string $body): array
{
    return collect(explode("\n", $body))
        ->filter(fn (string $line): bool => str_starts_with($line, 'data: ') && $line !== 'data: [DONE]')
        ->map(fn (string $line): array => json_decode(substr($line, 6), true, 512, JSON_THROW_ON_ERROR))
        ->values()
        ->all();
}

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.providers.anthropic.api_key', 'sk-ant-test');
});

it('answers a completion with the registered name and its own id, revealing no provider', function (string $provider): void {
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(anonOpenAiResponse([anonOpenAiText('Hello there.')])),
        'api.anthropic.com/*' => Http::response(anonAnthropicResponse([['type' => 'text', 'text' => 'Hello there.']])),
    ])->preventStrayRequests();

    registerAnon($provider);

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'team-brain',
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ]);

    $response->assertOk();
    expect($response->json('model'))->toBe('team-brain')
        ->and($response->json('id'))->toMatch('/^chatcmpl-[A-Za-z0-9]{29}$/')
        ->and($response->json('choices.0.message.content'))->toBe('Hello there.')
        ->and(array_keys($response->json()))->toBe(['id', 'object', 'created', 'model', 'usage', 'choices']);
    expectOpenAiUsageOnly($response->json('usage'));
    expectAnonymous($response, (string) $response->getContent());
})->with(['openai', 'anthropic']);

it('generates a fresh completion id for every response', function (): void {
    Http::fake(['api.openai.com/v1/responses' => Http::response(anonOpenAiResponse([anonOpenAiText('Hi.')]))])->preventStrayRequests();

    registerAnon('openai');

    $request = ['model' => 'team-brain', 'messages' => [['role' => 'user', 'content' => 'Hi']]];

    expect($this->postJson('prism/openai/v1/chat/completions', $request)->json('id'))
        ->not->toBe($this->postJson('prism/openai/v1/chat/completions', $request)->json('id'));
});

it('returns tool calls under generated call_ ids, revealing no provider', function (string $provider): void {
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(anonOpenAiResponse([
            anonOpenAiText('Over to you.'),
            anonOpenAiCall('one', '{"reason":"a"}'),
            anonOpenAiCall('two', '{"reason":"b"}'),
        ])),
        'api.anthropic.com/*' => Http::response(anonAnthropicResponse([
            ['type' => 'text', 'text' => 'Over to you.'],
            ['type' => 'tool_use', 'id' => 'toolu_LEAKone7731', 'name' => 'hand_back', 'input' => ['reason' => 'a']],
            ['type' => 'tool_use', 'id' => 'toolu_LEAKtwo7731', 'name' => 'hand_back', 'input' => ['reason' => 'b']],
        ], 'tool_use')),
    ])->preventStrayRequests();

    registerAnon($provider, withTool: true);

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'team-brain',
        'messages' => [['role' => 'user', 'content' => 'Done.']],
    ]);

    $response->assertOk();

    $calls = $response->json('choices.0.message.tool_calls');
    expect($calls)->toHaveCount(2)
        ->and($calls[0]['id'])->toMatch('/^call_[A-Za-z0-9]{24}$/')
        ->and($calls[1]['id'])->toMatch('/^call_[A-Za-z0-9]{24}$/')
        ->and($calls[0]['id'])->not->toBe($calls[1]['id'])
        ->and(json_decode((string) $calls[0]['function']['arguments'], true))->toBe(['reason' => 'a'])
        ->and(json_decode((string) $calls[1]['function']['arguments'], true))->toBe(['reason' => 'b'])
        ->and($response->json('model'))->toBe('team-brain')
        ->and($response->json('id'))->toMatch('/^chatcmpl-[A-Za-z0-9]{29}$/')
        ->and($response->json('choices.0.finish_reason'))->toBe('tool_calls');
    expectOpenAiUsageOnly($response->json('usage'));
    expectAnonymous($response, (string) $response->getContent());
})->with(['openai', 'anthropic']);

it('streams every chunk under one generated id and the registered name, revealing no provider', function (string $provider): void {
    $this->freezeTime();

    Http::fake([
        'api.openai.com/v1/responses' => Http::response(anonOpenAiStream(), 200, ['Content-Type' => 'text/event-stream']),
        'api.anthropic.com/*' => Http::response(anonAnthropicStream(), 200, ['Content-Type' => 'text/event-stream']),
    ])->preventStrayRequests();

    registerAnon($provider);

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'team-brain',
        'messages' => [['role' => 'user', 'content' => 'Hi']],
        'stream' => true,
    ]);

    $body = $response->streamedContent();
    $chunks = anonChunks($body);

    expect($chunks)->toHaveCount(3)
        ->and(implode('', array_map(fn (array $chunk): string => $chunk['choices'][0]['delta']['content'], $chunks)))->toBe('Hello there.')
        ->and(array_unique(array_column($chunks, 'id')))->toHaveCount(1)
        ->and($chunks[0]['id'])->toMatch('/^chatcmpl-[A-Za-z0-9]{29}$/')
        ->and(array_unique(array_column($chunks, 'model')))->toBe(['team-brain'])
        ->and(array_unique(array_column($chunks, 'created')))->toHaveCount(1)
        ->and($body)->toEndWith("data: [DONE]\n");
    expectAnonymous($response, $body);
})->with(['openai', 'anthropic']);

it('ends a failed stream with a generic error event, revealing no provider', function (string $provider): void {
    Exceptions::fake();

    $leak = ['error' => ['type' => 'overloaded_error', 'message' => 'claude-LEAK-served-7731 at https://LEAK.internal is overloaded']];

    Http::fake([
        'api.openai.com/v1/responses' => Http::response($leak, 500),
        'api.anthropic.com/*' => Http::response($leak, 500),
    ])->preventStrayRequests();

    registerAnon($provider);

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'team-brain',
        'messages' => [['role' => 'user', 'content' => 'Hi']],
        'stream' => true,
    ]);

    $body = $response->streamedContent();

    expect(anonChunks($body))->toBe([['error' => [
        'message' => 'The server could not complete the request.',
        'type' => 'server_error',
        'code' => null,
    ]]])
        ->and($body)->toEndWith("data: [DONE]\n");
    expectAnonymous($response, $body);

    Exceptions::assertReportedCount(1);
})->with(['openai', 'anthropic']);

it('answers a failed completion with the generic error, revealing no provider', function (string $provider): void {
    Exceptions::fake();

    $leak = ['error' => ['type' => 'overloaded_error', 'message' => 'claude-LEAK-served-7731 at https://LEAK.internal is overloaded']];

    Http::fake([
        'api.openai.com/v1/responses' => Http::response($leak, 500),
        'api.anthropic.com/*' => Http::response($leak, 500),
    ])->preventStrayRequests();

    registerAnon($provider);

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'team-brain',
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ]);

    $response->assertStatus(500);
    expectAnonymous($response, (string) $response->getContent());
})->with(['openai', 'anthropic']);

it('names the registered prism, not the string the request matched it with', function (): void {
    Http::fake(['api.openai.com/v1/responses' => Http::response(anonOpenAiResponse([anonOpenAiText('Hi.')]))])->preventStrayRequests();

    PrismServer::register('1000', fn (): PendingRequest => Prism::text()->using('openai', ANON_OPENAI_REQUESTED_MODEL));

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => '1e3',
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ]);

    $response->assertOk();
    expect($response->json('model'))->toBe('1000');
});

it('keeps one response consistent: calls sharing a provider id share the generated id', function (): void {
    $generator = Mockery::mock(PendingRequest::class);
    $generator->allows('withMessages')->andReturnSelf();
    $generator->allows('tools')->andReturn([anonReturnedTool()]);
    $generator->allows('withTools')->andReturnSelf();
    $generator->expects('asText')->andReturn(new Response(
        steps: collect(),
        text: '',
        finishReason: FinishReason::ToolCalls,
        toolCalls: [
            new ToolCall(id: 'toolu_LEAKsame', name: 'hand_back', arguments: ['n' => 1]),
            new ToolCall(id: 'toolu_LEAKother', name: 'hand_back', arguments: ['n' => 2]),
            new ToolCall(id: 'toolu_LEAKsame', name: 'hand_back', arguments: ['n' => 1]),
            new ToolCall(id: '', name: 'hand_back', arguments: []),
            new ToolCall(id: '', name: 'hand_back', arguments: []),
        ],
        toolResults: [],
        usage: new Usage(1, 1),
        meta: new Meta('msg_LEAKmeta', 'claude-LEAK-meta'),
        messages: collect(),
    ));

    PrismServer::register('team-brain', fn (): PendingRequest => $generator);

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'team-brain',
        'messages' => [['role' => 'user', 'content' => 'Bye']],
    ]);

    $ids = array_column($response->json('choices.0.message.tool_calls'), 'id');

    expect($ids)->toHaveCount(5)
        ->and($ids[0])->toBe($ids[2])
        ->and($ids[1])->not->toBe($ids[0])
        ->and($ids[3])->not->toBe($ids[4])
        ->and(array_unique($ids))->toHaveCount(4);
    expectAnonymous($response, (string) $response->getContent());
});
