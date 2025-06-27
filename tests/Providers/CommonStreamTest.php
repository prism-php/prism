<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Usage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', env('GEMINI_API_KEY', 'sss-1234567890'));
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'aaa-1234567890'));
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY', 'ooo-1234567890'));
});

it('can maintain conversation with history', function (Provider $provider, string $model, string $fixtureKey): void {
    FixtureResponse::fakeResponseSequence('*', $fixtureKey.'/stream-common-conversation-with-history');

    $temperatureFake = [
        'San Francisco' => 65,
        'San Diego' => 75,
        'Las Vegas' => 90,
    ];

    $weatherTool = Tool::as('weather')
        ->for('Get current weather conditions')
        ->withStringParameter('city', 'The city to get weather for')
        ->using(fn (string $city): string =>
            // Your weather API logic here
            "The weather in {$city} is sunny and ".$temperatureFake[$city] ?? 72 .'°F.');

    $conversation = [
        'What is the weather like in San Francisco?',
        'Recommend activities?',
        'What about San Diego and Las Vegas?',
        'Recommend activities in San Diego and Vegas. I like water activities.',
        'Please provide brief overview of all cities we talked, so I can choose.',
    ];

    $history = collect();
    $allResponses = collect();

    foreach ($conversation as $message) {
        $history->push(new UserMessage($message));

        $stream = Prism::text()
            ->using($provider, $model)
            ->withMaxSteps(10)
            ->withSystemPrompt('You are a helpful assistant for outdoor activities recommendations, you can use the weather tool.')
            ->withTools([$weatherTool])
            ->withMessages($history->toArray())
            ->asStream();

        $responses = [];
        foreach ($stream as $chunk) {
            if (! isset($responses[$chunk->meta->id])) {
                $responses[$chunk->meta->id] = [
                    'id' => $chunk->meta->id,
                    'model' => $chunk->meta->model,
                    'type' => $chunk->toolCalls ? 'tool' : 'text',
                    'text' => '',
                ];
            }

            if ($chunk->chunkType === ChunkType::ToolCall) {
                $responses[$chunk->meta->id]['type'] = 'tool';
                $responses[$chunk->meta->id]['toolCalls'] = $chunk->toolCalls;
            }

            if ($chunk->chunkType === ChunkType::ToolResult) {
                $responses[$chunk->meta->id]['type'] = 'tool';
                $responses[$chunk->meta->id]['toolResults'] = $chunk->toolResults;
            }

            if ($chunk->chunkType === ChunkType::Text) {
                $responses[$chunk->meta->id]['text'] .= $chunk->text;
            }

            if ($chunk->finishReason) {
                $responses[$chunk->meta->id]['finishReason'] = $chunk->finishReason;
            }

            if ($chunk->usage) {
                $responses[$chunk->meta->id]['usage'] = $chunk->usage;
            }
        }

        foreach ($responses as $response) {
            if ($response['type'] === 'tool') {
                $history->push(new AssistantMessage($response['text'], toolCalls: $response['toolCalls']));
                $history->push(new ToolResultMessage($response['toolResults']));
            }
            if ($response['type'] === 'text') {
                $history->push(new AssistantMessage($response['text']));
            }

            $allResponses->push($response);
        }
    }

    expect($history)->toHaveCount(14)
        // 1 for SF and 1 for SD and LV
        ->and($history->filter(fn ($m): bool => $m instanceof ToolResultMessage))->toHaveCount(2)
        ->and($history->last())->toBeInstanceOf(AssistantMessage::class)
        // it remembers conversation about all cities
        ->and($history->last()->content)->toContain('San Francisco', 'San Diego', 'Las Vegas')
        // it remembers weather for all cities (tool results)
        ->and($history->last()->content)->toContain(...array_values($temperatureFake))
        // check responses that is what would be saved in theory to storage
        ->and($allResponses)->toHaveCount(7)
        // has usage data
        ->and($allResponses->map->usage)->each->toBeInstanceOf(Usage::class)
        // has completion tokens
        ->and($allResponses->map->usage->map->promptTokens)->each->toBeGreaterThan(0)
        // has id
        ->and($allResponses->map->id)->each->toBeString()
        // and all ids are unique
        ->and($allResponses->map->id)->unique()->toHaveCount($allResponses->count());

})->with([
    [Provider::Gemini, 'gemini-2.5-flash', 'gemini'],
    [Provider::Anthropic, 'claude-sonnet-4-20250514', 'anthropic'],
    [Provider::OpenAI, 'gpt-4.1-mini', 'openai'],
]);
