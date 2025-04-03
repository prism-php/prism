<?php

namespace Tests\Http;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\PrismServer;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

beforeEach(function (): void {
    config()->set('prism.prism_server.enabled', true);
});

it('handles chat requests successfully', function (): void {
    $generator = Mockery::mock(PendingRequest::class);

    $generator->expects('withMessages')
        ->withArgs(fn ($messages): bool => $messages[0] instanceof UserMessage
            && $messages[0]->text() === 'Who are you?')
        ->andReturnSelf();

    $textResponse = new Response(
        steps: collect(),
        text: "I'm Nyx!",
        finishReason: FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        usage: new Usage(10, 10),
        meta: new Meta('cmp_asdf123', 'gpt-4'),
        responseMessages: collect([
            new AssistantMessage("I'm Nyx!"),
        ]),
        messages: collect(),
    );

    $generator->expects('asText')
        ->andReturn($textResponse);

    PrismServer::register(
        'nyx',
        fn () => $generator
    );

    /** @var TestResponse */
    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'nyx',
        'messages' => [[
            'role' => 'user',
            'content' => 'Who are you?',
        ]],
    ]);

    $response->assertOk();

    $now = now();

    Carbon::setTestNow($now);
    expect($response->json())->toBe([
        'id' => 'cmp_asdf123',
        'object' => 'chat.completion',
        'created' => $now->timestamp,
        'model' => 'gpt-4',
        'usage' => [
            'prompt_tokens' => $textResponse->usage->promptTokens,
            'completion_tokens' => $textResponse->usage->completionTokens,
            'total_tokens' => $textResponse->usage->promptTokens
                    + $textResponse->usage->completionTokens,
        ],
        'choices' => [
            [
                'index' => 0,
                'message' => [
                    'content' => "I'm Nyx!",
                    'role' => 'assistant',
                ],
                'finish_reason' => 'stop',
            ],
        ],
    ]);
});

it('handles streaming requests', function (): void {
    $generator = Mockery::mock(PendingRequest::class);

    $generator->expects('withMessages')
        ->withArgs(fn ($messages): bool => $messages[0] instanceof UserMessage
            && $messages[0]->text() === 'Who are you?')
        ->andReturnSelf();

    $meta = new Meta('cmp_asdf123', 'gpt-4');
    $chunk = new Chunk(
        text: "I'm Nyx!",
        meta: $meta
    );

    $generator->expects('asStream')
        ->andReturn((function () use ($chunk) {
            yield $chunk;
        })());

    PrismServer::register(
        'nyx',
        fn () => $generator
    );

    /** @var TestResponse */
    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'nyx',
        'messages' => [[
            'role' => 'user',
            'content' => 'Who are you?',
        ]],
        'stream' => true,
    ]);

    $streamParts = array_filter(explode("\n", $response->streamedContent()));

    $data = Str::of($streamParts[0])->substr(6);

    $now = now();
    Carbon::setTestNow($now);

    expect(json_decode($data, true))->toBe([
        'id' => 'cmp_asdf123',
        'object' => 'chat.completion.chunk',
        'created' => $now->timestamp,
        'model' => 'gpt-4',
        'choices' => [
            [
                'delta' => [
                    'role' => 'assistant',
                    'content' => "I'm Nyx!",
                ],
            ],
        ],
    ]);

    expect(count($streamParts) > 1)->toBeTrue();
    $lastPart = array_pop($streamParts);
    expect((string) Str::of($lastPart)->substr(6))->toBe('[DONE]');
});

it('handles invalid model requests', function (): void {
    /** @var TestResponse */
    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'nyx',
    ]);

    $response->assertServerError();

    expect($response->json('error.message'))->toContain('nyx');
});

it('handles missing prism', function (): void {
    /** @var TestResponse */
    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'nyx',
        'messages' => [[
            'role' => 'user',
            'content' => 'Who are you?',
        ]],
    ]);

    $response->assertServerError();
    expect($response->json('error.message'))
        ->toBe('Prism "nyx" is not registered with PrismServer');
});
