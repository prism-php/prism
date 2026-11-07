<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Providers\Anthropic\Handlers\Text;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', 'fake-key');
});

/**
 * @return array<int, array<string, mixed>>
 */
function anthropicRequestBodies(): array
{
    return Http::recorded()
        ->map(fn (array $pair): array => json_decode((string) $pair[0]->body(), true))
        ->values()
        ->all();
}

/**
 * @return array<int, array<string, mixed>>
 */
function anthropicFixtureContent(string $fixture): array
{
    return json_decode((string) file_get_contents(__DIR__."/../../Fixtures/anthropic/{$fixture}"), true)['content'];
}

function anthropicReplayWeatherTool(int &$calls = 0): \Prism\Prism\Tool
{
    return Tool::as('get_weather')
        ->for('Get current weather for a city')
        ->withStringParameter('city', 'The city')
        ->using(function (string $city) use (&$calls): string {
            $calls++;

            return match ($city) {
                'London' => '14°C',
                'Rome' => '22°C',
                default => '20°C and sunny',
            };
        });
}

describe('round-trip replay', function (): void {
    it('replays every signed thinking block interleaved with tool use, in order and untouched', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-interleaved-thinking-tools');

        $response = Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([anthropicReplayWeatherTool()])
            ->withMaxSteps(3)
            ->withPrompt('Which is warmer, London or Rome?')
            ->asText();

        $firstContent = anthropicFixtureContent('text-interleaved-thinking-tools-1.json');
        $bodies = anthropicRequestBodies();

        expect($bodies)->toHaveCount(2);
        expect($bodies[1]['messages'][1])->toEqual(['role' => 'assistant', 'content' => $firstContent]);
        expect(array_column($bodies[1]['messages'][1]['content'], 'type'))
            ->toBe(['thinking', 'text', 'tool_use', 'thinking', 'tool_use']);
        expect($bodies[1]['messages'][2]['content'])->sequence(
            fn ($result) => $result->tool_use_id->toBe('toolu_01JeMGUQbXRKAfx4XzsXuMZi'),
            fn ($result) => $result->tool_use_id->toBe('toolu_015YQXzj7bt4PomNWSsBu7cF'),
        );

        $firstStep = $response->steps->first();
        expect($firstStep->additionalContent['content_blocks'])->toBe($firstContent);
        expect($firstStep->additionalContent['thinking_blocks'])->toBe([$firstContent[0], $firstContent[3]]);
        expect($firstStep->additionalContent['thinking'])->toBe($firstContent[0]['thinking']);
        expect($firstStep->additionalContent['thinking_signature'])->toBe($firstContent[0]['signature']);
        expect($firstStep->additionalContent['stop_reason'])->toBe('tool_use');
        expect($firstStep->additionalContent)->not->toHaveKey('stop_details');
    });

    it('extracts thinking without a thinking option, keeping empty thinking with its signature', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-interleaved-thinking-tools');

        $response = Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([anthropicReplayWeatherTool()])
            ->withMaxSteps(3)
            ->withPrompt('Which is warmer, London or Rome?')
            ->asText();

        $finalContent = anthropicFixtureContent('text-interleaved-thinking-tools-2.json');

        expect(anthropicRequestBodies()[0])->not->toHaveKey('thinking');
        expect($response->text)->toBe('Rome is warmer: 71.6°F versus 57.2°F in London.');
        expect($response->additionalContent['thinking'])->toBe('');
        expect($response->additionalContent['thinking_signature'])->toBe($finalContent[0]['signature']);
        expect($response->additionalContent['thinking_blocks'])->toBe([$finalContent[0]]);
    });

    it('replays redacted thinking blocks in place', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-redacted-thinking');

        $response = Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([anthropicReplayWeatherTool()])
            ->withMaxSteps(3)
            ->withPrompt('Weather in Paris?')
            ->asText();

        $firstContent = anthropicFixtureContent('text-redacted-thinking-1.json');

        expect(anthropicRequestBodies()[1]['messages'][1]['content'])->toEqual($firstContent);
        expect($response->steps->first()->additionalContent['thinking_blocks'])
            ->toBe([$firstContent[0], $firstContent[1]]);
        expect($response->steps->first()->additionalContent['thinking'])->toBe('Need the weather in Paris.'."\n\n");
    });

    it('replays a persisted response conversation, final assistant turn included, when the conversation continues', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-interleaved-thinking-tools');

        $response = Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([anthropicReplayWeatherTool()])
            ->withMaxSteps(3)
            ->withPrompt('Which is warmer, London or Rome?')
            ->asText();

        $loopMessages = anthropicRequestBodies()[1]['messages'];
        $finalContent = anthropicFixtureContent('text-interleaved-thinking-tools-2.json');

        $persisted = unserialize(serialize($response->messages->all()));

        $continued = Text::buildHttpRequestPayload(Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([anthropicReplayWeatherTool()])
            ->withMessages([...$persisted, new UserMessage('And Paris?')])
            ->toRequest());

        $messages = json_decode((string) json_encode($continued['messages']), true);

        expect($messages)->toHaveCount(5);
        expect(array_slice($messages, 0, 3))->toEqual($loopMessages);
        expect($messages[3])->toEqual(['role' => 'assistant', 'content' => $finalContent]);
        expect($messages[3]['content'][0]['signature'])->toBe($finalContent[0]['signature']);
        expect($messages[4])->toEqual(['role' => 'user', 'content' => [['type' => 'text', 'text' => 'And Paris?']]]);
    });
});

describe('terminal turns with tool use', function (): void {
    it('stores a refused turn without its tool use or unanswered server tool use, so the conversation can continue', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-refusal-after-tool-use');

        $calls = 0;

        $response = Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([anthropicReplayWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withPrompt('Weather in Paris?')
            ->asText();

        $raw = anthropicFixtureContent('text-refusal-after-tool-use-1.json');

        expect($calls)->toBe(0);
        expect($response->finishReason)->toBe(FinishReason::Refusal);
        expect($response->toolCalls)->toBe([]);
        expect($response->steps->sole()->raw['content'])->toBe($raw);
        expect($response->additionalContent['content_blocks'])->toBe([$raw[0], $raw[1], $raw[2]]);

        Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([anthropicReplayWeatherTool($calls)])
            ->withMessages([...unserialize(serialize($response->messages->all())), new UserMessage('Something else then.')])
            ->asText();

        $replayed = anthropicRequestBodies()[1]['messages'];

        expect($calls)->toBe(0);
        expect(array_column($replayed[1]['content'], 'type'))->toBe(['text', 'server_tool_use', 'web_search_tool_result']);
        expect(array_column($replayed[1]['content'], 'id'))->not->toContain('toolu_01SyncRefusalTool00001', 'srvtoolu_01SyncRefusalSearch02');
        expect($replayed[2]['role'])->toBe('user');
    });

    it('stores a max_tokens turn without the tool call it never executed', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-max-tokens-after-tool-use');

        $calls = 0;

        $response = Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([anthropicReplayWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withPrompt('Weather in Paris and London?')
            ->asText();

        expect($response->finishReason)->toBe(FinishReason::Length);
        expect($response->toolCalls)->toBe([]);

        Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withTools([anthropicReplayWeatherTool($calls)])
            ->withMessages([...$response->messages, new UserMessage('Just Paris, please.')])
            ->asText();

        expect($calls)->toBe(0);
        expect(anthropicRequestBodies()[1]['messages'][1])->toBe([
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Checking both cities.']],
        ]);
    });

    it('stores a refused structured turn without its tool use', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-refusal-after-tool-use');

        $calls = 0;

        $response = Prism::structured()
            ->using('anthropic', 'claude-opus-5-5')
            ->withSchema(new ObjectSchema('weather', 'Weather report', [new StringSchema('summary', 'Summary')], ['summary']))
            ->withTools([anthropicReplayWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withPrompt('Weather in Paris?')
            ->asStructured();

        $persisted = $response->steps->last()->messages;
        $assistant = end($persisted);

        expect($calls)->toBe(0);
        expect($response->finishReason)->toBe(FinishReason::Refusal);
        expect($assistant)->toBeInstanceOf(AssistantMessage::class);
        expect($assistant->toolCalls)->toBe([]);

        $replayed = MessageMap::map([...$persisted, new UserMessage('Something else then.')]);

        expect(array_column($replayed[1]['content'], 'type'))->toBe(['text', 'server_tool_use', 'web_search_tool_result']);
    });
});

describe('stored message compatibility', function (): void {
    it('replays legacy messages that only carry the thinking pair, including empty thinking', function (): void {
        $mapped = MessageMap::map([
            new UserMessage('Weather?'),
            new AssistantMessage('', [new ToolCall('toolu_1', 'get_weather', ['city' => 'Paris'])], [
                'thinking' => '',
                'thinking_signature' => 'sig-legacy',
            ]),
            new ToolResultMessage([new ToolResult('toolu_1', 'get_weather', ['city' => 'Paris'], '20°C')]),
        ]);

        expect($mapped[1]['content'])->toBe([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-legacy'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => ['city' => 'Paris']],
        ]);
    });

    it('prefers content_blocks over thinking_blocks over the legacy pair', function (): void {
        $legacyPair = ['thinking' => 'legacy', 'thinking_signature' => 'sig-legacy'];
        $thinkingBlocks = [
            ['type' => 'thinking', 'thinking' => 'one', 'signature' => 'sig-1'],
            ['type' => 'redacted_thinking', 'data' => 'opaque'],
        ];
        $contentBlocks = [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-raw'],
            ['type' => 'text', 'text' => 'raw text'],
        ];

        $withAll = MessageMap::map([new AssistantMessage('derived', [], [
            ...$legacyPair, 'thinking_blocks' => $thinkingBlocks, 'content_blocks' => $contentBlocks,
        ])]);
        $withoutContentBlocks = MessageMap::map([new AssistantMessage('derived', [], [
            ...$legacyPair, 'thinking_blocks' => $thinkingBlocks,
        ])]);

        expect($withAll[0]['content'])->toBe($contentBlocks);
        expect($withoutContentBlocks[0]['content'])->toBe([
            ...$thinkingBlocks,
            ['type' => 'text', 'text' => 'derived'],
        ]);
    });

    it('puts assistant cache_control on the last cacheable replayed block', function (): void {
        $message = (new AssistantMessage('', [], [
            'content_blocks' => [
                ['type' => 'text', 'text' => 'Looking it up.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => []],
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
            ],
        ]))->withProviderOptions(['cacheType' => 'ephemeral']);

        $content = json_decode((string) json_encode(MessageMap::map([$message])[0]['content']), true);

        expect($content)->toBe([
            ['type' => 'text', 'text' => 'Looking it up.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => [], 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
        ]);
        expect((string) json_encode(MessageMap::map([$message])[0]['content'][1]['input']))->toBe('{}');
    });
});

describe('stop reasons', function (): void {
    it('normalises refusal stop details', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-refusal-stop-details');

        $response = Prism::text()
            ->using('anthropic', 'claude-fable-5-1')
            ->withPrompt('Something declined')
            ->asText();

        expect($response->finishReason)->toBe(FinishReason::Refusal);
        expect($response->text)->toBe('');
        expect($response->additionalContent['stop_reason'])->toBe('refusal');
        expect($response->additionalContent['stop_details'])->toBe([
            'type' => 'refusal',
            'category' => 'cyber',
            'explanation' => 'This request was declined because it could enable cyber harm.',
        ]);
        expect($response->steps->sole()->additionalContent['stop_details']['category'])->toBe('cyber');
    });

    it('leaves the empty assistant turn of a refusal before output out of the next request', function (): void {
        $fixtures = __DIR__.'/../../Fixtures/anthropic';

        Http::fake([
            'v1/messages' => Http::sequence([
                Http::response((string) file_get_contents("{$fixtures}/text-refusal-stop-details-1.json")),
                Http::response((string) file_get_contents("{$fixtures}/generate-text-with-a-prompt-1.json")),
            ]),
        ])->preventStrayRequests();

        $refused = Prism::text()
            ->using('anthropic', 'claude-fable-5-1')
            ->withPrompt('Something declined')
            ->asText();

        expect($refused->messages->last())->toBeInstanceOf(AssistantMessage::class);

        Prism::text()
            ->using('anthropic', 'claude-fable-5-1')
            ->withMessages([...$refused->messages->all(), new UserMessage('Something else')])
            ->asText();

        expect(anthropicRequestBodies()[1]['messages'])->toBe([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Something declined']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Something else']]],
        ]);
    });

    it('omits assistant messages that map to no content from any replay path', function (): void {
        $mapped = MessageMap::map([
            new UserMessage('First'),
            new AssistantMessage('', [], ['content_blocks' => []]),
            new AssistantMessage('', [], ['thinking_blocks' => []]),
            new AssistantMessage(''),
            new UserMessage('Second'),
            new AssistantMessage('Still here'),
        ]);

        expect($mapped)->toBe([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'First']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Second']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Still here']]],
        ]);
    });

    it('maps model_context_window_exceeded to Length', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-context-window-exceeded');

        $response = Prism::text()
            ->using('anthropic', 'claude-opus-5-5')
            ->withPrompt('A very long conversation')
            ->asText();

        expect($response->finishReason)->toBe(FinishReason::Length);
        expect($response->text)->toBe('The partial answer');
        expect($response->additionalContent['stop_reason'])->toBe('model_context_window_exceeded');
    });
});

it('sends the tool_use caller field and empty thinking text back on the wire', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-interleaved-thinking-tools');

    Prism::text()
        ->using('anthropic', 'claude-opus-5-5')
        ->withTools([anthropicReplayWeatherTool()])
        ->withMaxSteps(3)
        ->withPrompt('Which is warmer, London or Rome?')
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $assistant = $request->data()['messages'][1] ?? null;

        return $assistant !== null
            && ($assistant['content'][2]['caller'] ?? null) === ['type' => 'direct']
            && $assistant['content'][3]['thinking'] === '';
    });
});

it('sends a prompt and a replayed assistant turn of "0" on the wire', function (): void {
    $fixtures = __DIR__.'/../../Fixtures/anthropic';

    Http::fake([
        'v1/messages' => Http::sequence([
            Http::response((string) file_get_contents("{$fixtures}/generate-text-with-a-prompt-1.json")),
            Http::response((string) file_get_contents("{$fixtures}/generate-text-with-a-prompt-1.json")),
        ]),
    ])->preventStrayRequests();

    Prism::text()
        ->using('anthropic', 'claude-fable-5-1')
        ->withPrompt('0')
        ->asText();

    Prism::text()
        ->using('anthropic', 'claude-fable-5-1')
        ->withMessages([
            new UserMessage('How many?'),
            new AssistantMessage('0'),
            new UserMessage('Are you sure?'),
        ])
        ->asText();

    expect(anthropicRequestBodies()[0]['messages'])->toBe([
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => '0']]],
    ]);
    expect(anthropicRequestBodies()[1]['messages'])->toBe([
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'How many?']]],
        ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '0']]],
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Are you sure?']]],
    ]);
});
