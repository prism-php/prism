<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Tests\Fixtures\FixtureResponse;

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeResponseSequence('api/chat', 'ollama/stream-basic-text');

    $response = Prism::text()
        ->using('ollama', 'phi4')
        ->withPrompt('Who are you?')
        ->asStream();

    $text = '';
    $chunks = [];

    foreach ($response as $chunk) {
        $chunks[] = $chunk;
        $text .= $chunk->text;
    }

    expect($chunks)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'http://localhost:11434/api/chat'
            && $body['stream'] === true;
    });
});

it('can process tool calls in a stream response', function (): void {
    FixtureResponse::fakeResponseSequence('api/chat', 'ollama/stream-with-tools');

    $calculator = Tool::as('calculator')
        ->for('useful for performing mathematical calculations')
        ->withStringParameter('expression', 'The mathematical expression to evaluate')
        ->using(fn (string $expression): string => 'Result: 42');

    $response = Prism::text()
        ->using('ollama', 'mistral')
        ->withTools([$calculator])
        ->withPrompt('Calculate 20+22')
        ->asStream();

    $chunks = [];
    $toolCalls = [];
    $fullResponse = '';

    foreach ($response as $chunk) {
        $chunks[] = $chunk;

        foreach ($chunk->toolCalls as $toolCall) {
            $toolCalls[] = $toolCall;
        }

        $fullResponse .= $chunk->text;
    }

    expect($chunks)->not->toBeEmpty();
    expect($toolCalls)->toHaveCount(1);
    expect($toolCalls[0]->name)->toBe('calculator');
    expect($fullResponse)->toContain('Result: 42');

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'http://localhost:11434/api/chat'
            && isset($body['tools'])
            && count($body['tools']) === 1;
    });
});

it('can process multiple tool calls of the same type in a conversation', function (): void {
    FixtureResponse::fakeResponseSequence('api/chat', 'ollama/stream-multi-tool-conversation');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => "The weather will be 75° and sunny in {$city}"),

        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => "Search results for: {$query}"),
    ];

    $response = Prism::text()
        ->using('ollama', 'mistral')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('How is the weather in Paris?')
        ->asStream();

    $chunks = [];
    $toolCalls = [];
    $fullResponse = '';

    foreach ($response as $chunk) {
        $chunks[] = $chunk;

        foreach ($chunk->toolCalls as $toolCall) {
            $toolCalls[] = $toolCall;
        }

        $fullResponse .= $chunk->text;
    }

    expect($chunks)->not->toBeEmpty();
    expect($toolCalls)->toHaveCount(1);
    expect($toolCalls[0]->name)->toBe('weather');
    expect($toolCalls[0]->arguments())->toBe(['city' => 'Paris']);

    // Verify we made requests for a conversation with multiple tool calls
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'http://localhost:11434/api/chat' && isset($body['tools']);
    });
});
