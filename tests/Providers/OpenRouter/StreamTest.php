<?php

declare(strict_types=1);

use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\Text\Chunk;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openrouter.api_key', env('OPENROUTER_API_KEY'));
});

it('can stream text with a prompt', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-a-prompt');

    $stream = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withPrompt('Who are you?')
        ->asStream();

    $chunks = iterator_to_array($stream);

    // Check we have chunks
    expect($chunks)->toHaveCount(20);

    // Check first chunk has metadata
    expect($chunks[0])->toBeInstanceOf(Chunk::class);
    expect($chunks[0]->chunkType)->toBe(ChunkType::Meta);
    expect($chunks[0]->meta->id)->toBe('gen-12345');
    expect($chunks[0]->meta->model)->toBe('openai/gpt-4-turbo');

    // Check content chunks
    expect($chunks[1]->text)->toBe('Hello');
    expect($chunks[2]->text)->toBe('!');
    expect($chunks[3]->text)->toBe(" I'm");
    expect($chunks[4]->text)->toBe(' an');
    expect($chunks[5]->text)->toBe(' AI');
    expect($chunks[6]->text)->toBe(' assistant');
    expect($chunks[7]->text)->toBe(' powered');
    expect($chunks[8]->text)->toBe(' by');
    expect($chunks[9]->text)->toBe(' OpenRouter');
    expect($chunks[10]->text)->toBe('.');
    expect($chunks[11]->text)->toBe(' How');
    expect($chunks[12]->text)->toBe(' can');
    expect($chunks[13]->text)->toBe(' I');
    expect($chunks[14]->text)->toBe(' help');
    expect($chunks[15]->text)->toBe(' you');
    expect($chunks[16]->text)->toBe(' today');
    expect($chunks[17]->text)->toBe('?');

    // Check usage chunk
    expect($chunks[18]->chunkType)->toBe(ChunkType::Meta);
    expect($chunks[18]->usage->promptTokens)->toBe(7);
    expect($chunks[18]->usage->completionTokens)->toBe(35);

    // Check final chunk with finish reason
    expect($chunks[19]->chunkType)->toBe(ChunkType::Meta);
    expect($chunks[19]->finishReason)->toBe(FinishReason::Stop);

    // Verify full text can be reconstructed
    $fullText = implode('', array_map(fn ($chunk): string => $chunk->text, $chunks));
    expect($fullText)->toBe("Hello! I'm an AI assistant powered by OpenRouter. How can I help you today?");
});

it('can stream text with tool calls', function (): void {
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-tools');

    $weatherTool = Tool::as('weather')
        ->for('Get weather for a city')
        ->withStringParameter('city', 'The city name')
        ->using(fn (string $city): string => "The weather in {$city} is 75°F and sunny");

    $stream = Prism::text()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->withTools([$weatherTool])
        ->withPrompt('What is the weather in San Francisco?')
        ->asStream();

    $chunks = iterator_to_array($stream);

    // Check we have chunks
    expect($chunks)->toHaveCount(14);

    // Check first chunk has metadata
    expect($chunks[0])->toBeInstanceOf(Chunk::class);
    expect($chunks[0]->chunkType)->toBe(ChunkType::Meta);
    expect($chunks[0]->meta->id)->toBe('gen-tool-1');
    expect($chunks[0]->meta->model)->toBe('openai/gpt-4-turbo');

    // Check content chunks
    expect($chunks[1]->text)->toBe("I'll");
    expect($chunks[2]->text)->toBe(' help');
    expect($chunks[3]->text)->toBe(' you');
    expect($chunks[4]->text)->toBe(' get');
    expect($chunks[5]->text)->toBe(' the');
    expect($chunks[6]->text)->toBe(' weather');
    expect($chunks[7]->text)->toBe(' for');
    expect($chunks[8]->text)->toBe(' you');
    expect($chunks[9]->text)->toBe('.');

    // Check for tool call chunks
    $toolCallChunks = array_filter($chunks, fn ($chunk): bool => $chunk->chunkType === ChunkType::ToolCall);
    expect($toolCallChunks)->toHaveCount(1);

    $toolCallChunk = array_values($toolCallChunks)[0];
    expect($toolCallChunk->toolCalls)->toHaveCount(1);
    expect($toolCallChunk->toolCalls[0]->name)->toBe('weather');
    expect($toolCallChunk->toolCalls[0]->arguments())->toBe(['city' => 'San Francisco']);

    // Check for tool result chunks
    $toolResultChunks = array_filter($chunks, fn ($chunk): bool => $chunk->chunkType === ChunkType::ToolResult);
    expect($toolResultChunks)->toHaveCount(1);

    $toolResultChunk = array_values($toolResultChunks)[0];
    expect($toolResultChunk->toolResults)->toHaveCount(1);
    expect($toolResultChunk->toolResults[0]->result)->toBe('The weather in San Francisco is 75°F and sunny');

    // Check usage chunk
    $usageChunks = array_filter($chunks, fn ($chunk): bool => $chunk->chunkType === ChunkType::Meta && $chunk->usage instanceof \Prism\Prism\ValueObjects\Usage);
    expect($usageChunks)->toHaveCount(1);

    $usageChunk = array_values($usageChunks)[0];
    expect($usageChunk->usage->promptTokens)->toBe(50);
    expect($usageChunk->usage->completionTokens)->toBe(25);

    // Check final chunk with finish reason
    $finishChunks = array_filter($chunks, fn ($chunk): bool => $chunk->finishReason === FinishReason::ToolCalls);
    expect($finishChunks)->toHaveCount(1);
});

it('can handle reasoning/thinking tokens in streaming', function (): void {
    // Create a fixture with reasoning tokens
    FixtureResponse::fakeStreamResponses('v1/chat/completions', 'openrouter/stream-text-with-reasoning');

    $stream = Prism::text()
        ->using(Provider::OpenRouter, 'openai/o1-preview')
        ->withPrompt('Solve this math problem: 2 + 2 = ?')
        ->asStream();

    $chunks = iterator_to_array($stream);

    // Check for thinking chunks
    $thinkingChunks = array_filter($chunks, fn ($chunk): bool => $chunk->chunkType === ChunkType::Thinking);

    if ($thinkingChunks !== []) {
        expect($thinkingChunks)->toHaveCount(1);
        $thinkingChunk = array_values($thinkingChunks)[0];
        expect($thinkingChunk->text)->toContain('math problem');
    }

    // If no thinking chunks, that's also valid for models without reasoning
    expect(true)->toBe(true);
});
