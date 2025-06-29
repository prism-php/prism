<?php

namespace Tests\Http;

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

use function Pest\Laravel\freezeTime;

it('handles chat requests successfully', function (): void {
    freezeTime();
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

it('handles streaming requests', function (): void {
    freezeTime();
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

    expect(json_decode($data, true))->toBe([
        'id' => 'cmp_asdf123',
        'object' => 'chat.completion.chunk',
        'created' => now()->timestamp,
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

it('handles multimodal messages with image URL', function (): void {
    freezeTime();
    $generator = Mockery::mock(PendingRequest::class);

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
        responseMessages: collect([
            new AssistantMessage('I can see a test image.'),
        ]),
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
    freezeTime();
    $generator = Mockery::mock(PendingRequest::class);

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
        responseMessages: collect([
            new AssistantMessage('This appears to be a screenshot.'),
        ]),
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
    freezeTime();
    $generator = Mockery::mock(PendingRequest::class);

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
        responseMessages: collect([
            new AssistantMessage('Both images show different scenes.'),
        ]),
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
    freezeTime();
    $generator = Mockery::mock(PendingRequest::class);

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
        responseMessages: collect([
            new AssistantMessage('Hello! I can see the image you shared.'),
        ]),
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
