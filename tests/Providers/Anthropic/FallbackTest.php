<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', 'fake-key');
});

function anthropicFallbackFixture(string $fixture): array
{
    return json_decode((string) file_get_contents(__DIR__."/../../Fixtures/anthropic/{$fixture}"), true);
}

it('sends fallbacks as a top-level field with the fallback beta header', function (mixed $fallbacks): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-fallback-before-output');

    Prism::text()
        ->using('anthropic', 'claude-opus-5-5')
        ->withPrompt('Hello')
        ->withProviderOptions(['fallbacks' => $fallbacks])
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->data()['fallbacks'] === $fallbacks
        && $request->header('anthropic-beta') === ['server-side-fallback-2026-07-01']);
})->with([
    'default routing' => ['default'],
    'named chain' => [[['model' => 'claude-opus-4-8'], ['model' => 'claude-opus-5', 'max_tokens' => 2048]]],
]);

it('does not send fallbacks or the beta header unless asked', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-fallback-before-output');

    Prism::text()->using('anthropic', 'claude-opus-5-5')->withPrompt('Hello')->asText();

    Http::assertSent(fn (Request $request): bool => ! array_key_exists('fallbacks', $request->data())
        && ! $request->hasHeader('anthropic-beta'));
});

it('reports the serving model, the fallback boundary and the per-attempt usage', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-fallback-before-output');

    $response = Prism::text()
        ->using('anthropic', 'claude-opus-5-5')
        ->withPrompt('Hello')
        ->withProviderOptions(['fallbacks' => 'default'])
        ->asText();

    expect($response->meta->model)->toBe('claude-opus-4-8');
    expect($response->text)->toBe('Hi! How can I help you today?');
    expect($response->additionalContent['fallbacks'])->toBe([
        ['from' => 'claude-opus-5-5', 'to' => 'claude-opus-4-8'],
    ]);
    expect($response->additionalContent['usage_iterations'])->sequence(
        fn ($attempt) => $attempt->type->toBe('message')->model->toBe('claude-opus-5-5')->input_tokens->toBe(535),
        fn ($attempt) => $attempt->type->toBe('fallback_message')->model->toBe('claude-opus-4-8')->output_tokens->toBe(264),
    );
    expect($response->usage->promptTokens)->toBe(412);
    expect($response->usage->completionTokens)->toBe(264);
});

it('identifies sticky routing by usage iterations without a fallback block', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-fallback-sticky');

    $response = Prism::text()
        ->using('anthropic', 'claude-opus-5-5')
        ->withPrompt('Continue')
        ->withProviderOptions(['fallbacks' => 'default'])
        ->asText();

    expect($response->meta->model)->toBe('claude-opus-4-8');
    expect($response->additionalContent)->not->toHaveKey('fallbacks');
    expect($response->additionalContent['usage_iterations'])->toBe([[
        'input_tokens' => 700,
        'output_tokens' => 12,
        'cache_read_input_tokens' => 0,
        'cache_creation_input_tokens' => 0,
        'cache_creation' => ['ephemeral_5m_input_tokens' => 0, 'ephemeral_1h_input_tokens' => 0],
        'type' => 'fallback_message',
        'model' => 'claude-opus-4-8',
    ]]);
});

it('returns a final refusal after every fallback declined', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-fallback-refusal');

    $response = Prism::text()
        ->using('anthropic', 'claude-opus-5-5')
        ->withPrompt('Something declined twice')
        ->withProviderOptions(['fallbacks' => 'default'])
        ->asText();

    expect($response->finishReason)->toBe(FinishReason::Refusal);
    expect($response->additionalContent['stop_details'])
        ->category->toBe('cyber')
        ->recommended_model->toBeNull();
    expect($response->additionalContent['fallbacks'])->toBe([
        ['from' => 'claude-opus-5-5', 'to' => 'claude-opus-4-8'],
    ]);
});

it('keeps the fallback block in place when replaying a fallback-served tool turn', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-fallback-tool-loop');

    $calls = 0;

    $response = Prism::text()
        ->using('anthropic', 'claude-opus-5-5')
        ->withTools([
            Tool::as('get_weather')
                ->for('Get current weather for a city')
                ->withStringParameter('city', 'The city')
                ->using(function (string $city) use (&$calls): string {
                    $calls++;

                    return '20°C and sunny';
                }),
        ])
        ->withMaxSteps(3)
        ->withPrompt('Weather in Paris?')
        ->withProviderOptions(['fallbacks' => 'default'])
        ->asText();

    $bodies = Http::recorded()->map(fn (array $pair): array => json_decode((string) $pair[0]->body(), true))->values();
    $firstContent = anthropicFallbackFixture('text-fallback-tool-loop-1.json')['content'];

    expect($calls)->toBe(1);
    expect($bodies[1]['messages'][1]['content'])->toEqual($firstContent);
    expect($response->steps)->toHaveCount(2);
    expect($response->steps->map(fn ($step) => $step->additionalContent['usage_iterations'])->all())->sequence(
        fn ($iterations) => $iterations->toHaveCount(2),
        fn ($iterations) => $iterations->toHaveCount(1),
    );
    expect($response->steps->first()->meta->model)->toBe('claude-opus-4-8');

    Http::assertSent(fn (Request $request): bool => $request->header('anthropic-beta') === ['server-side-fallback-2026-07-01']
        && $request->data()['fallbacks'] === 'default');
});

describe('fallback echo projection', function (): void {
    it('drops declined thinking, connector text, client tool use and unpaired server tool use before the final boundary', function (): void {
        $blocks = [
            ['type' => 'text', 'text' => 'Partial from opus-5-5. '],
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-declined-1'],
            ['type' => 'connector_text', 'text' => 'narration'],
            ['type' => 'server_tool_use', 'id' => 'srvtoolu_paired', 'name' => 'web_search', 'input' => ['query' => 'a']],
            ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_paired', 'content' => []],
            ['type' => 'server_tool_use', 'id' => 'srvtoolu_unpaired', 'name' => 'web_search', 'input' => ['query' => 'b']],
            ['type' => 'tool_use', 'id' => 'toolu_declined', 'name' => 'get_weather', 'input' => ['city' => 'Berlin']],
            ['type' => 'fallback', 'from' => ['model' => 'claude-opus-5-5'], 'to' => ['model' => 'claude-opus-5']],
            ['type' => 'redacted_thinking', 'data' => 'declined-too'],
            ['type' => 'text', 'text' => 'Partial from opus-5. '],
            ['type' => 'tool_use', 'id' => 'toolu_declined_2', 'name' => 'get_weather', 'input' => ['city' => 'Oslo']],
            ['type' => 'fallback', 'from' => ['model' => 'claude-opus-5'], 'to' => ['model' => 'claude-opus-4-8']],
            ['type' => 'thinking', 'thinking' => 'served', 'signature' => 'sig-served'],
            ['type' => 'text', 'text' => 'Answer from opus-4-8.'],
            ['type' => 'tool_use', 'id' => 'toolu_served', 'name' => 'get_weather', 'input' => ['city' => 'Paris']],
        ];

        $mapped = MessageMap::map([new AssistantMessage('', [], ['content_blocks' => $blocks])]);

        expect($mapped[0]['content'])->toBe([
            $blocks[0],
            $blocks[3],
            $blocks[4],
            $blocks[7],
            $blocks[9],
            $blocks[11],
            $blocks[12],
            $blocks[13],
            $blocks[14],
        ]);
    });

    it('replays a turn without fallback blocks unchanged', function (): void {
        $blocks = [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
            ['type' => 'connector_text', 'text' => 'narration'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => ['city' => 'Paris']],
        ];

        expect(MessageMap::map([new AssistantMessage('', [], ['content_blocks' => $blocks])])[0]['content'])->toBe($blocks);
    });
});
