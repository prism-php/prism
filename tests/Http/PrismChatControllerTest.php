<?php

namespace Tests\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\PrismServer;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

it('handles chat requests successfully', function (): void {
    $this->freezeTime();
    $generator = Mockery::mock(PendingRequest::class);
    $generator->allows('tools')->andReturn([]);

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

    expect($response->json())->toBe([
        'id' => 'cmp_asdf123',
        'object' => 'chat.completion',
        'created' => now()->timestamp,
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

it('reports cached input inside prompt_tokens like the OpenAI API', function (): void {
    $generator = Mockery::mock(PendingRequest::class);
    $generator->allows('tools')->andReturn([]);
    $generator->expects('withMessages')->andReturnSelf();
    $generator->expects('asText')->andReturn(new Response(
        steps: collect(),
        text: "I'm Nyx!",
        finishReason: FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        usage: new Usage(
            promptTokens: 25,
            completionTokens: 94,
            cacheWriteInputTokens: 300,
            cacheReadInputTokens: 12752,
            thoughtTokens: 87,
        ),
        meta: new Meta('cmp_asdf123', 'gpt-4'),
        messages: collect(),
    ));

    PrismServer::register('nyx', fn () => $generator);

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'nyx',
        'messages' => [['role' => 'user', 'content' => 'Who are you?']],
    ]);

    $response->assertOk();

    expect($response->json('usage'))->toBe([
        'prompt_tokens' => 13077,
        'completion_tokens' => 94,
        'total_tokens' => 13171,
        'prompt_tokens_details' => ['cached_tokens' => 12752],
        'completion_tokens_details' => ['reasoning_tokens' => 87],
    ]);
});

it('reports a cache read of zero but leaves out details it does not know', function (): void {
    $generator = Mockery::mock(PendingRequest::class);
    $generator->allows('tools')->andReturn([]);
    $generator->expects('withMessages')->andReturnSelf();
    $generator->expects('asText')->andReturn(new Response(
        steps: collect(),
        text: "I'm Nyx!",
        finishReason: FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        usage: new Usage(promptTokens: 12, completionTokens: 8, cacheReadInputTokens: 0),
        meta: new Meta('cmp_asdf123', 'gpt-4'),
        messages: collect(),
    ));

    PrismServer::register('nyx', fn () => $generator);

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'nyx',
        'messages' => [['role' => 'user', 'content' => 'Who are you?']],
    ]);

    expect($response->json('usage'))->toBe([
        'prompt_tokens' => 12,
        'completion_tokens' => 8,
        'total_tokens' => 20,
        'prompt_tokens_details' => ['cached_tokens' => 0],
    ]);
});

it('handles streaming requests', function (): void {
    $this->freezeTime();
    $generator = Mockery::mock(PendingRequest::class);
    $generator->allows('tools')->andReturn([]);

    $generator->expects('withMessages')
        ->withArgs(fn ($messages): bool => $messages[0] instanceof UserMessage
            && $messages[0]->text() === 'Who are you?')
        ->andReturnSelf();

    $event = new TextDeltaEvent(
        id: 'cmp_asdf123',
        timestamp: time(),
        delta: "I'm Nyx!",
        messageId: EventID::generate()
    );

    $generator->expects('asStream')
        ->andReturn((function () use ($event) {
            yield $event;
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

    expect(json_decode($data, true))->toBe([
        'id' => 'cmp_asdf123',
        'object' => 'chat.completion.chunk',
        'created' => now()->timestamp,
        'model' => 'unknown',
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

it('answers an unknown model with 404 and a fixed message (#150)', function (): void {
    /** @var TestResponse */
    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'nyx',
        'messages' => [[
            'role' => 'user',
            'content' => 'Who are you?',
        ]],
    ]);

    $response->assertNotFound();
    expect($response->json('error'))->toBe([
        'message' => 'The requested model does not exist.',
        'type' => 'invalid_request_error',
        'code' => 'model_not_found',
    ]);
    expect($response->getContent())->not->toContain('PrismServer');
});

it('answers messages it cannot read with 422 and a fixed message (#150)', function (): void {
    PrismServer::register('nyx', fn (): PendingRequest => Mockery::mock(PendingRequest::class));

    /** @var TestResponse */
    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'nyx',
        'messages' => [['role' => 'not-a-role', 'content' => 'x']],
    ]);

    $response->assertUnprocessable();
    expect($response->json('error'))->toBe([
        'message' => 'The messages could not be read.',
        'type' => 'invalid_request_error',
        'code' => 'invalid_messages',
    ]);
});

it('never returns an exception message to the caller, and reports it instead (#150)', function (): void {
    Exceptions::fake();
    PrismServer::register('nyx', function (): PendingRequest {
        throw new \RuntimeException('SQLSTATE[28000] password=hunter2 at https://internal.example/v1 /var/www/.env');
    });

    /** @var TestResponse */
    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'nyx',
        'messages' => [['role' => 'user', 'content' => 'Who are you?']],
    ]);

    $response->assertServerError();
    expect($response->json('error'))->toBe([
        'message' => 'The server could not complete the request.',
        'type' => 'server_error',
        'code' => null,
    ]);
    foreach (['SQLSTATE', 'hunter2', 'internal.example', '.env'] as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }
    Exceptions::assertReported(
        fn (\RuntimeException $e): bool => str_contains($e->getMessage(), 'hunter2')
    );
});

it('still answers a malformed request with 422', function (): void {
    /** @var TestResponse */
    $response = $this->postJson('prism/openai/v1/chat/completions', ['messages' => 'nope']);

    $response->assertUnprocessable();
});

it('handles multimodal messages with image URL', function (): void {
    $this->freezeTime();
    $generator = Mockery::mock(PendingRequest::class);
    $generator->allows('tools')->andReturn([]);

    $generator->expects('withMessages')
        ->withArgs(function ($messages): bool {
            $message = $messages[0];

            return $message instanceof UserMessage
                && $message->text() === 'What is in this image?'
                && count($message->images()) === 1
                && $message->images()[0]->url() === 'https://example.com/test.jpg'
                && $message->images()[0]->isUrl();
        })
        ->andReturnSelf();

    $textResponse = new Response(
        steps: collect(),
        text: 'I can see a test image.',
        finishReason: FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        usage: new Usage(15, 12),
        meta: new Meta('cmp_image123', 'gpt-4-vision'),
        messages: collect(),
    );

    $generator->expects('asText')
        ->andReturn($textResponse);

    PrismServer::register(
        'vision-model',
        fn () => $generator
    );

    /** @var TestResponse */
    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'vision-model',
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'What is in this image?'],
                ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/test.jpg']],
            ],
        ]],
    ]);

    $response->assertOk();
    expect($response->json('choices.0.message.content'))->toBe('I can see a test image.');
});

it('handles multimodal messages with base64 image', function (): void {
    $this->freezeTime();
    $generator = Mockery::mock(PendingRequest::class);
    $generator->allows('tools')->andReturn([]);

    $base64Image = base64_encode('fake-image-data');

    $generator->expects('withMessages')
        ->withArgs(function ($messages) use ($base64Image): bool {
            $message = $messages[0];

            return $message instanceof UserMessage
                && $message->text() === 'Analyze this screenshot'
                && count($message->images()) === 1
                && $message->images()[0]->base64() === $base64Image
                && $message->images()[0]->mimeType() === 'image/png'
                && ! $message->images()[0]->isUrl();
        })
        ->andReturnSelf();

    $textResponse = new Response(
        steps: collect(),
        text: 'This appears to be a screenshot.',
        finishReason: FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        usage: new Usage(20, 15),
        meta: new Meta('cmp_base64_123', 'gpt-4-vision'),
        messages: collect(),
    );

    $generator->expects('asText')
        ->andReturn($textResponse);

    PrismServer::register(
        'vision-model',
        fn () => $generator
    );

    /** @var TestResponse */
    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'vision-model',
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Analyze this screenshot'],
                ['type' => 'image_url', 'image_url' => ['url' => "data:image/png;base64,{$base64Image}"]],
            ],
        ]],
    ]);

    $response->assertOk();
    expect($response->json('choices.0.message.content'))->toBe('This appears to be a screenshot.');
});

it('handles multimodal messages with multiple images', function (): void {
    $this->freezeTime();
    $generator = Mockery::mock(PendingRequest::class);
    $generator->allows('tools')->andReturn([]);

    $generator->expects('withMessages')
        ->withArgs(function ($messages): bool {
            $message = $messages[0];

            return $message instanceof UserMessage
                && $message->text() === 'Compare these two images'
                && count($message->images()) === 2
                && $message->images()[0]->url() === 'https://example.com/image1.jpg'
                && $message->images()[1]->url() === 'https://example.com/image2.jpg';
        })
        ->andReturnSelf();

    $textResponse = new Response(
        steps: collect(),
        text: 'Both images show different scenes.',
        finishReason: FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        usage: new Usage(25, 18),
        meta: new Meta('cmp_multi123', 'gpt-4-vision'),
        messages: collect(),
    );

    $generator->expects('asText')
        ->andReturn($textResponse);

    PrismServer::register(
        'vision-model',
        fn () => $generator
    );

    /** @var TestResponse */
    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'vision-model',
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Compare these two images'],
                ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image1.jpg']],
                ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image2.jpg']],
            ],
        ]],
    ]);

    $response->assertOk();
    expect($response->json('choices.0.message.content'))->toBe('Both images show different scenes.');
});

it('handles mixed simple and multimodal messages', function (): void {
    $this->freezeTime();
    $generator = Mockery::mock(PendingRequest::class);
    $generator->allows('tools')->andReturn([]);

    $generator->expects('withMessages')
        ->withArgs(fn ($messages): bool => count($messages) === 2
            && $messages[0] instanceof UserMessage
            && $messages[0]->text() === 'Hello!'
            && $messages[0]->images() === []
            && $messages[1] instanceof UserMessage
            && $messages[1]->text() === 'What about this image?'
            && count($messages[1]->images()) === 1)
        ->andReturnSelf();

    $textResponse = new Response(
        steps: collect(),
        text: 'Hello! I can see the image you shared.',
        finishReason: FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        usage: new Usage(20, 15),
        meta: new Meta('cmp_mixed123', 'gpt-4-vision'),
        messages: collect(),
    );

    $generator->expects('asText')
        ->andReturn($textResponse);

    PrismServer::register(
        'vision-model',
        fn () => $generator
    );

    /** @var TestResponse */
    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'vision-model',
        'messages' => [
            [
                'role' => 'user',
                'content' => 'Hello!',
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'What about this image?'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/test.jpg']],
                ],
            ],
        ],
    ]);

    $response->assertOk();
    expect($response->json('choices.0.message.content'))->toBe('Hello! I can see the image you shared.');
});

it('lets the app render Prism Server errors (#150)', function (): void {
    PrismServer::register('nyx', function (): PendingRequest {
        throw new \RuntimeException('internal detail');
    });
    PrismServer::renderErrorsUsing(fn (Throwable $e): JsonResponse => response()->json(['error' => ['message' => 'Try again later.', 'type' => 'app_error']], 503));

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'nyx',
        'messages' => [['role' => 'user', 'content' => 'Who are you?']],
    ]);

    $response->assertStatus(503);
    expect($response->json('error'))->toBe(['message' => 'Try again later.', 'type' => 'app_error']);
});

it('keeps the safe default when the renderer returns null (#150)', function (): void {
    PrismServer::renderErrorsUsing(fn (Throwable $e): null => null);

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'nyx',
        'messages' => [['role' => 'user', 'content' => 'Who are you?']],
    ]);

    $response->assertNotFound();
    expect($response->json('error.code'))->toBe('model_not_found');
});

it('falls back to the safe default when the renderer throws, without leaking either message (#150)', function (): void {
    Exceptions::fake();
    PrismServer::register('nyx', function (): PendingRequest {
        throw new \RuntimeException('provider body password=hunter2');
    });
    PrismServer::renderErrorsUsing(function (Throwable $e): never {
        throw new \LogicException('renderer bug at /var/www/app.php');
    });

    $response = $this->postJson('prism/openai/v1/chat/completions', [
        'model' => 'nyx',
        'messages' => [['role' => 'user', 'content' => 'Who are you?']],
    ]);

    $response->assertServerError();
    expect($response->json('error.message'))->toBe('The server could not complete the request.');
    expect($response->getContent())->not->toContain('hunter2');
    expect($response->getContent())->not->toContain('/var/www');
    Exceptions::assertReported(fn (\LogicException $e): bool => str_contains($e->getMessage(), 'renderer bug'));
    Exceptions::assertReported(fn (\RuntimeException $e): bool => str_contains($e->getMessage(), 'hunter2'));
});
