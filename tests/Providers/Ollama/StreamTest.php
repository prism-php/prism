<?php

declare(strict_types=1);

namespace Tests\Providers\Ollama;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.ollama.url', env('OLLAMA_URL', 'http://localhost:11434'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-basic-text');

    $response = Prism::text()
        ->using('ollama', 'granite3-dense:8b')
        ->withPrompt('Who are you?')
        ->asStream();

    $text = '';
    $chunks = [];
    $lastChunkHasFinishReason = false;

    foreach ($response as $chunk) {
        $chunks[] = $chunk;
        $text .= $chunk->text;

        if ($chunk->finishReason !== null) {
            $lastChunkHasFinishReason = true;
        }
    }

    expect($chunks)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();
    expect($lastChunkHasFinishReason)->toBeTrue();

    // Last chunk should have a finish reason of "stop"
    $lastChunk = $chunks[count($chunks) - 1];
    expect($lastChunk->finishReason)->toBe(\Prism\Prism\Enums\FinishReason::Stop);
});

it('can generate text using tools with streaming', function (): void {
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-with-tools');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),

        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm today'),
    ];

    $response = Prism::text()
        ->using('ollama', 'qwen3:14b')
        ->withTools($tools)
        ->withMaxSteps(6)
        ->withPrompt('What time is the tigers game today in Detroit and should I wear a coat?')
        ->asStream();

    $text = '';
    $chunks = [];
    $toolCalls = [];
    $toolResults = [];
    $finishReasonFound = false;

    foreach ($response as $chunk) {
        $chunks[] = $chunk;

        if ($chunk->chunkType === ChunkType::ToolCall) {
            $toolCalls = array_merge($toolCalls, $chunk->toolCalls);
        }

        if ($chunk->chunkType === ChunkType::ToolResult) {
            $toolResults = array_merge($toolResults, $chunk->toolResults);
        }

        if ($chunk->finishReason !== null) {
            $finishReasonFound = true;
            expect($chunk->finishReason)->toBe(FinishReason::Stop);
        }

        $text .= $chunk->text;
    }

    expect($chunks)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();

    expect($toolCalls)->toHaveCount(2);
    expect($toolResults)->toHaveCount(2);

    // For the basic tools test, validate completion state
    expect($finishReasonFound)->toBeTrue();
});

it('throws a PrismRateLimitedException with a 429 response code', function (): void {
    Http::fake([
        '*' => Http::response(
            status: 429,
        ),
    ])->preventStrayRequests();

    $response = Prism::text()
        ->using('ollama', 'granite3-dense:8b')
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $chunk) {
        // Don't remove me rector!
    }
})->throws(PrismRateLimitedException::class);

it('includes think parameter when thinking is enabled for streaming', function (): void {
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-with-thinking-enabled');

    $response = Prism::text()
        ->using('ollama', 'gpt-oss')
        ->withPrompt('Test prompt')
        ->withProviderOptions(['thinking' => true])
        ->asStream();

    // Consume the stream to trigger the HTTP request
    foreach ($response as $chunk) {
        break;
    }

    Http::assertSent(function (Request $request): true {
        $body = $request->data();
        expect($body)->toHaveKey('think');
        expect($body['think'])->toBe(true);

        return true;
    });
});

it('does not include think parameter when not provided for streaming', function (): void {
    FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-without-thinking');

    $response = Prism::text()
        ->using('ollama', 'gpt-oss')
        ->withPrompt('Test prompt')
        ->asStream();

    // Consume the stream to trigger the HTTP request
    foreach ($response as $chunk) {
        break;
    }

    Http::assertSent(function (Request $request): true {
        $body = $request->data();
        expect($body)->not->toHaveKey('think');

        return true;
    });
});

it('emits thinking chunks when provider sends thinking field', function (): void {
    \Tests\Fixtures\FixtureResponse::fakeStreamResponses('api/chat', 'ollama/stream-with-thinking');

    $response = Prism::text()
        ->using('ollama', 'gpt-oss:20b')
        ->withPrompt('Should I bring a jacket?')
        ->asStream();

    $sawThinking = false;
    $sawText = false;
    $thinkingTexts = [];
    $finalText = '';
    $lastFinishReason = null;

    foreach ($response as $chunk) {
        if ($chunk->chunkType === ChunkType::Thinking) {
            $sawThinking = true;
            $thinkingTexts[] = $chunk->text;
        }

        if ($chunk->chunkType === ChunkType::Text) {
            $sawText = true;
            $finalText .= $chunk->text;
        }

        if ($chunk->finishReason !== null) {
            $lastFinishReason = $chunk->finishReason;
        }
    }

    expect($sawThinking)->toBeTrue();
    expect($sawText)->toBeTrue();
    expect($thinkingTexts)->not->toBeEmpty();
    expect($finalText)->toContain('Here is the answer:');
    expect($lastFinishReason)->toBe(FinishReason::Stop);
});
