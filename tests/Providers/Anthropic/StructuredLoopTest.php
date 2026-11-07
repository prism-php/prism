<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Providers\Anthropic\Handlers\Structured;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', 'fake-key');
});

function anthropicLoopSchema(): ObjectSchema
{
    return new ObjectSchema('result', 'result', [
        new NumberSchema('total', 'total'),
        new StringSchema('note', 'note'),
    ], ['total', 'note']);
}

function anthropicLoopPriceTool(int &$calls = 0): \Prism\Prism\Tool
{
    return Tool::as('lookup_price')
        ->for('Look up the unit price of a SKU')
        ->withStringParameter('sku', 'SKU')
        ->using(function (string $sku) use (&$calls): string {
            $calls++;

            return '8.50';
        });
}

/**
 * @return array<int, array<string, mixed>>
 */
function anthropicLoopBodies(): array
{
    return Http::recorded()
        ->map(fn (array $pair): array => json_decode((string) $pair[0]->body(), true))
        ->values()
        ->all();
}

/**
 * @param  array<int, array<string, mixed>>  $messages
 * @return array<int, array{message: int, block: int}>
 */
function anthropicCacheMarkers(array $messages): array
{
    $markers = [];

    foreach ($messages as $messageIndex => $message) {
        foreach (is_array($message['content']) ? $message['content'] : [] as $blockIndex => $block) {
            if (isset($block['cache_control'])) {
                $markers[] = ['message' => $messageIndex, 'block' => $blockIndex];
            }
        }
    }

    return $markers;
}

function anthropicLoopFixtureContent(int $step): array
{
    return json_decode((string) file_get_contents(__DIR__."/../../Fixtures/anthropic/structured-thinking-tool-loop-{$step}.json"), true)['content'];
}

it('replays tool-step text and thinking across a multi-step native structured loop', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-thinking-tool-loop');

    $calls = 0;

    $response = Prism::structured()
        ->using('anthropic', 'claude-sonnet-5-5')
        ->withSchema(anthropicLoopSchema())
        ->withTools([anthropicLoopPriceTool($calls)])
        ->withMaxSteps(6)
        ->withPrompt('Price SKUs A1 through E5')
        ->asStructured();

    $bodies = anthropicLoopBodies();

    expect($calls)->toBe(5);
    expect($bodies)->toHaveCount(5);
    expect($response->structured)->toBe(['total' => 42.5, 'note' => 'All five SKUs priced.']);
    expect($response->text)->toBe('');

    $assistantTurns = array_values(array_filter($bodies[4]['messages'], fn (array $message): bool => $message['role'] === 'assistant'));

    expect($assistantTurns)->toHaveCount(4);

    foreach ([1, 2, 3, 4] as $step) {
        expect($assistantTurns[$step - 1]['content'])->toEqual(anthropicLoopFixtureContent($step));
    }

    expect($assistantTurns[0]['content'][1])->toBe(['type' => 'text', 'text' => 'Looking up A1 first.']);
    expect($response->steps->first()->additionalContent['thinking'])->toBe("Start with A1.\n\n");
});

it('maps thinking tokens per step and sums them across a structured loop', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-thinking-tool-loop');

    $response = Prism::structured()
        ->using('anthropic', 'claude-sonnet-5-5')
        ->withSchema(anthropicLoopSchema())
        ->withTools([anthropicLoopPriceTool()])
        ->withMaxSteps(6)
        ->withPrompt('Price SKUs A1 through E5')
        ->asStructured();

    expect($response->steps->map(fn ($step): ?int => $step->usage->thoughtTokens)->all())->toBe([40, 20, 0, 10, 10]);
    expect($response->usage)
        ->completionTokens->toBe(340)
        ->thoughtTokens->toBe(80);
});

it('keeps each request append-only across the loop', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-thinking-tool-loop');

    Prism::structured()
        ->using('anthropic', 'claude-sonnet-5-5')
        ->withSchema(anthropicLoopSchema())
        ->withTools([anthropicLoopPriceTool()])
        ->withMaxSteps(6)
        ->withPrompt('Price SKUs A1 through E5')
        ->asStructured();

    $bodies = anthropicLoopBodies();
    $counter = count($bodies);

    for ($index = 1; $index < $counter; $index++) {
        $previous = $bodies[$index - 1]['messages'];

        expect(array_slice($bodies[$index]['messages'], 0, count($previous)))->toEqual($previous);
    }
});

it('moves a single rolling tool-result cache breakpoint across 4+ structured tool rounds', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-thinking-tool-loop');

    Prism::structured()
        ->using('anthropic', 'claude-sonnet-5-5')
        ->withSchema(anthropicLoopSchema())
        ->withTools([anthropicLoopPriceTool()])
        ->withMaxSteps(6)
        ->withMessages([
            (new UserMessage('Price SKUs A1 through E5'))->withProviderOptions(['cacheType' => 'ephemeral']),
        ])
        ->withProviderOptions(['tool_result_cache_type' => 'ephemeral'])
        ->asStructured();

    $bodies = anthropicLoopBodies();

    expect(anthropicCacheMarkers($bodies[0]['messages']))->toBe([['message' => 0, 'block' => 0]]);

    foreach (array_slice($bodies, 1) as $body) {
        $messages = $body['messages'];
        $lastMessage = count($messages) - 1;

        expect($messages[$lastMessage]['content'][0]['type'])->toBe('tool_result');
        expect(anthropicCacheMarkers($messages))->toBe([
            ['message' => 0, 'block' => 0],
            ['message' => $lastMessage, 'block' => count($messages[$lastMessage]['content']) - 1],
        ]);
    }

    expect($bodies[3]['messages'][6]['content'])->toHaveCount(2);
});

describe('tool calling mode', function (): void {
    it('appends the structured output instruction only before the first request', function (): void {
        FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-tool-mode-loop');

        $response = Prism::structured()
            ->using('anthropic', 'claude-sonnet-5-5')
            ->withSchema(anthropicLoopSchema())
            ->withTools([anthropicLoopPriceTool()])
            ->withMaxSteps(5)
            ->withPrompt('Price SKUs A1 and B2')
            ->withProviderOptions(['use_tool_calling' => true])
            ->asStructured();

        $bodies = anthropicLoopBodies();
        $instructions = array_filter(
            $bodies[2]['messages'],
            fn (array $message): bool => $message['role'] === 'user'
                && str_contains((string) json_encode($message['content']), 'Please use the output_structured_data tool')
        );

        expect($bodies)->toHaveCount(3);
        expect(array_keys($instructions))->toBe([1]);
        expect(array_slice($bodies[2]['messages'], 0, count($bodies[1]['messages'])))->toEqual($bodies[1]['messages']);
        expect($response->structured)->toBe(['total' => 21.28, 'note' => 'Two SKUs.']);
    });

    it('honours an explicit tool choice when there are no custom tools', function (?ToolChoice $choice, array $expected): void {
        $request = Prism::structured()
            ->using('anthropic', 'claude-opus-5-5')
            ->withSchema(anthropicLoopSchema())
            ->withPrompt('Total?')
            ->withProviderOptions(['use_tool_calling' => true]);

        if ($choice instanceof ToolChoice) {
            $request->withToolChoice($choice);
        }

        expect(Structured::buildHttpRequestPayload($request->toRequest())['tool_choice'])->toBe($expected);
    })->with([
        'default forces the structured tool' => [null, ['type' => 'tool', 'name' => 'output_structured_data']],
        'explicit auto' => [ToolChoice::Auto, ['type' => 'auto']],
        'explicit any' => [ToolChoice::Any, ['type' => 'any']],
    ]);

    it('omits tool_choice when manual thinking is requested through thinking.type', function (): void {
        $request = Prism::structured()
            ->using('anthropic', 'claude-sonnet-4-5')
            ->withSchema(anthropicLoopSchema())
            ->withPrompt('Total?')
            ->withProviderOptions(['use_tool_calling' => true, 'thinking' => ['type' => 'enabled', 'budget_tokens' => 2048]]);

        expect(Structured::buildHttpRequestPayload($request->toRequest()))->not->toHaveKey('tool_choice');
    });
});
