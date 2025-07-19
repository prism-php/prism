<?php

declare(strict_types=1);

namespace Prism\tests\Providers\DeepSeek;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.deepseek.api_key', env('DEEPSEEK_API_KEY'));
    config()->set('prism.providers.deepseek.url', env('DEEPSEEK_URL', 'https://api.deepseek.com'));
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'deepseek/stream-basic-text-responses');

    $response = Prism::text()
        ->using(Provider::DeepSeek, 'deepseek-chat')
        ->withPrompt('Who are you?')
        ->asStream();

    $text = '';
    $chunks = [];

    $responseId = null;
    $model = null;

    foreach ($response as $chunk) {
        if ($chunk->meta) {
            $responseId = $chunk->meta?->id;
            $model = $chunk->meta?->model;
        }

        $chunks[] = $chunk;
        $text .= $chunk->text;
    }

    expect($chunks)->not
        ->toBeEmpty()
        ->and($text)->not
        ->toBeEmpty()
        ->and($responseId)->not
        ->toBeNull()
        ->and($model)->toBe('deepseek-chat');

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.deepseek.com/chat/completions'
            && $body['stream'] === true
            && $body['model'] === 'deepseek-chat';
    });
});

it('can generate text using tools with streaming', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'deepseek/stream-with-tools-responses');

    $tools = [
        Tool::as('get_weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),

        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => "Search results for: {$query}"),
    ];

    $response = Prism::text()
        ->using(Provider::DeepSeek, 'deepseek-chat')
        ->withTools($tools)
        ->withMaxSteps(4)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStream();

    $text = '';
    $chunks = [];
    $toolCalls = [];
    $toolResults = [];

    foreach ($response as $chunk) {
        $chunks[] = $chunk;

        if ($chunk->chunkType === ChunkType::ToolCall) {
            $toolCalls = array_merge($toolCalls, $chunk->toolCalls);
        }

        if ($chunk->chunkType === ChunkType::ToolResult) {
            $toolResults = array_merge($toolResults, $chunk->toolResults);
        }

        $text .= $chunk->text;
    }

    expect($chunks)->not
        ->toBeEmpty()
        ->and($toolCalls)->toHaveCount(2)
        ->and($toolResults)->toHaveCount(2);

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.deepseek.com/chat/completions'
            && isset($body['tools'])
            && $body['stream'] === true
            && $body['model'] === 'deepseek-chat';
    });
});

it('handles max_tokens parameter correctly', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'deepseek/stream-max-tokens-responses');

    $response = Prism::text()
        ->using(Provider::DeepSeek, 'deepseek-chat')
        ->withMaxTokens(1000)
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $chunk) {
        // Process stream
    }

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.deepseek.com/chat/completions'
            && $body['max_tokens'] === 1000;
    });
});

it('handles system prompts correctly', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'deepseek/stream-system-prompt-responses');

    $response = Prism::text()
        ->using(Provider::DeepSeek, 'deepseek-chat')
        ->withSystemPrompt('You are a helpful assistant.')
        ->withPrompt('Who are you?')
        ->asStream();

    foreach ($response as $chunk) {
        // Process stream
    }

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return count($body['messages']) === 2
            && $body['messages'][0]['role'] === 'system'
            && $body['messages'][1]['role'] === 'user';
    });
});

it('can handle reasoning/thinking tokens in streaming', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'deepseek/stream-with-reasoning-responses');

    $response = Prism::text()
        ->using(Provider::DeepSeek, 'deepseek-reasoner')
        ->withPrompt('Solve this complex math problem: What is 4 * 8?')
        ->asStream();

    $thinkingContent = '';
    $regularContent = '';
    $thinkingChunks = 0;
    $regularChunks = 0;

    foreach ($response as $chunk) {
        if ($chunk->chunkType === ChunkType::Thinking) {
            $thinkingContent .= $chunk->text;
            $thinkingChunks++;
        } elseif ($chunk->chunkType === ChunkType::Text) {
            $regularContent .= $chunk->text;
            $regularChunks++;
        }
    }

    expect($thinkingChunks)
        ->toBeGreaterThan(0)
        ->and($regularChunks)->toBeGreaterThan(0)
        ->and($thinkingContent)->not
        ->toBeEmpty()
        ->and($regularContent)->not
        ->toBeEmpty()
        ->and($thinkingContent)->toContain('answer')
        ->and($regularContent)->toContain('32');
});
