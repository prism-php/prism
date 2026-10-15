<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Streaming\StreamCollector;
use Prism\Prism\Tool as PrismTool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-test');
});

function openAIRequestBodies(): array
{
    return Http::recorded()
        ->map(fn (array $pair): array => json_decode((string) $pair[0]->body(), true))
        ->values()
        ->all();
}

function openAIInputShape(array $body): array
{
    return array_map(
        fn (array $item): string => ($item['type'] ?? 'message').':'.($item['role'] ?? $item['phase'] ?? $item['id'] ?? $item['call_id'] ?? ''),
        $body['input']
    );
}

function openAIWeatherTool(int &$calls = 0): PrismTool
{
    return Tool::as('get_weather')
        ->for('Get weather for a city')
        ->withStringParameter('city', 'The city')
        ->using(function (string $city) use (&$calls): string {
            $calls++;

            return "18°C and cloudy in {$city}";
        });
}

function openAIReportSchema(): ObjectSchema
{
    return new ObjectSchema('report', 'weather report', [
        new StringSchema('city', 'city'),
        new NumberSchema('temperature_c', 'temperature in C'),
        new StringSchema('summary', 'one sentence'),
    ], ['city', 'temperature_c', 'summary']);
}

/**
 * Function calls in a Responses API input without a function_call_output.
 *
 * @param  array<int, array<string, mixed>>  $input
 * @return array<int, string>
 */
function openAIUnansweredCalls(array $input): array
{
    $outputs = array_column(array_filter($input, fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'), 'call_id');

    return array_values(array_map(
        fn (array $call): string => $call['call_id'],
        array_filter(
            $input,
            fn (array $item): bool => ($item['type'] ?? null) === 'function_call' && ! in_array($item['call_id'] ?? null, $outputs, true)
        )
    ));
}

/**
 * Streams a turn through a StreamCollector the way a caller persisting the
 * conversation would, and returns the persisted (serialized and rebuilt)
 * messages.
 *
 * @return array<int, Message>
 */
function openAIPersistedStreamTurn(string $prompt, int &$calls): array
{
    $pendingRequest = Prism::text()
        ->using('openai', 'gpt-6.1-sol')
        ->withTools([openAIWeatherTool($calls)])
        ->withMaxSteps(3)
        ->withPrompt($prompt);

    $persisted = [];

    $collector = new StreamCollector($pendingRequest->asStream(), $pendingRequest, function ($request, Collection $messages) use (&$persisted): void {
        $persisted = $messages->all();
    });

    iterator_to_array($collector->collect(), false);

    return unserialize(serialize($persisted));
}

describe('text output items', function (): void {
    it('replays commentary, reasoning and calls in their original order on the next tool-loop request', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-text-commentary-tools');

        $response = Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withTools([openAIWeatherTool()])
            ->withMaxSteps(3)
            ->withProviderOptions(['store' => false, 'reasoning' => ['effort' => 'low']])
            ->withPrompt('Which is bigger, Detroit or Chicago? Check the weather there.')
            ->asText();

        $firstStep = $response->steps[0];

        expect($firstStep->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($firstStep->text)->toBe('Chicago has more people than Detroit, so I’ll check Chicago’s weather.')
            ->and($firstStep->additionalContent['phase'])->toBe('commentary')
            ->and(array_column($firstStep->additionalContent['output_items'], 'type'))->toBe(['reasoning', 'message', 'function_call'])
            ->and($firstStep->additionalContent['output_items'][0]['summary'])->toBe([]);

        expect($response->text)->toBe('It’s currently 18°C and cloudy in Chicago.')
            ->and($response->finishReason)->toBe(FinishReason::Stop)
            ->and($response->additionalContent['phase'])->toBe('final_answer');

        $bodies = openAIRequestBodies();
        $replayed = $bodies[1]['input'];

        expect($bodies)->toHaveCount(2)
            ->and(array_column($bodies, 'store'))->toBe([false, false])
            ->and(openAIInputShape($bodies[1]))->toBe([
                'message:user',
                'reasoning:rs_0347cc32954024db016ad126bcf9d487d1a73df290ccd7953e',
                'message:assistant',
                'function_call:fc_0347cc32954024db016ad126bd82b887d1988f98e16c76dae7',
                'function_call_output:call_nrtCABMHzuhPGOppbQqS8Z6L',
            ])
            ->and($replayed[1]['encrypted_content'])->toBe('gAAAAABq0Sa9PpQGKiwnL1Kxk859-step-one')
            ->and($replayed[1]['summary'])->toBe([])
            ->and($replayed[2]['phase'])->toBe('commentary')
            ->and($replayed[2]['id'])->toBe('msg_0347cc32954024db016ad126bd268887d1b28605706fec6747')
            ->and($replayed[2]['content'][0]['text'])->toBe('Chicago has more people than Detroit, so I’ll check Chicago’s weather.');
    });

    it('round trips stored response messages into a follow-up request', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-text-commentary-tools');

        $response = Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withTools([openAIWeatherTool()])
            ->withMaxSteps(3)
            ->withPrompt('Which is bigger, Detroit or Chicago? Check the weather there.')
            ->asText();

        $stored = json_decode(json_encode($response->messages->map->toArray()->all()), true);

        $restored = array_map(fn (array $message): UserMessage|AssistantMessage|ToolResultMessage => match ($message['type']) {
            'user' => new UserMessage($message['content']),
            'assistant' => new AssistantMessage($message['content'], [], $message['additional_content']),
            'tool_result' => new ToolResultMessage(array_map(
                fn (array $result): ToolResult => new ToolResult(
                    $result['tool_call_id'], $result['tool_name'], $result['args'], $result['result'], $result['tool_call_result_id'],
                ),
                $message['tool_results'],
            )),
        }, $stored);

        Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withTools([openAIWeatherTool()])
            ->withMessages([...$restored, new UserMessage('Should I bring a jacket?')])
            ->asText();

        $followUp = openAIRequestBodies()[2];

        expect(openAIInputShape($followUp))->toBe([
            'message:user',
            'reasoning:rs_0347cc32954024db016ad126bcf9d487d1a73df290ccd7953e',
            'message:assistant',
            'function_call:fc_0347cc32954024db016ad126bd82b887d1988f98e16c76dae7',
            'function_call_output:call_nrtCABMHzuhPGOppbQqS8Z6L',
            'reasoning:rs_0347cc32954024db016ad126be1a2c87d1b2b1a0e4f6a7c8d9',
            'message:assistant',
            'message:user',
        ])
            ->and($followUp['input'][5]['encrypted_content'])->toBe('gAAAAABq0Sa-Qx7step-two')
            ->and($followUp['input'][5]['summary'])->toBe([['type' => 'summary_text', 'text' => 'Answering from the tool result.']])
            ->and($followUp['input'][6]['phase'])->toBe('final_answer');
    });

    it('keeps consecutive reasoning items and multiple message phases without tools', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-text-phases');

        $response = Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withPrompt('What is 17*23?')
            ->asText();

        expect($response->text)->toBe('17 × 23 = 391.')
            ->and($response->finishReason)->toBe(FinishReason::Stop)
            ->and($response->additionalContent['phase'])->toBe('final_answer')
            ->and(array_column($response->additionalContent['output_items'], 'phase'))->toBe(['commentary', 'final_answer']);

        Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withMessages([...$response->messages, new UserMessage('Double it.')])
            ->asText();

        $followUp = openAIRequestBodies()[1];

        expect(openAIInputShape($followUp))->toBe([
            'message:user',
            'reasoning:rs_067694cf35d3bc0b006ad11f3068cc87d1b28a38bd22ebc456',
            'reasoning:rs_067694cf35d3bc0b006ad11f3070dd87d1c39b49ce33fcd567',
            'message:assistant',
            'message:assistant',
            'message:user',
        ])
            ->and(array_column($followUp['input'], 'phase'))->toBe(['commentary', 'final_answer'])
            ->and($followUp['input'][1]['encrypted_content'])->toBe('gAAAAABq0R8wgMSDRqH3-first')
            ->and($followUp['input'][2]['encrypted_content'])->toBe('gAAAAABq0R8wgMSDRqH3-second');
    });

    it('classifies a refusal without throwing and replays the refusal part', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-text-refusal');

        $response = Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withPrompt('Something the model refuses')
            ->asText();

        expect($response->finishReason)->toBe(FinishReason::Refusal)
            ->and($response->text)->toBe('')
            ->and($response->additionalContent['refusal'])->toBe('I can’t help with that.');

        Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withMessages([...$response->messages, new UserMessage('Something else then.')])
            ->asText();

        $replayedAssistant = openAIRequestBodies()[1]['input'][1];

        expect($replayedAssistant['content'])->toBe([['type' => 'refusal', 'refusal' => 'I can’t help with that.']])
            ->and($replayedAssistant['phase'])->toBe('final_answer');
    });

    it('returns a Length response for max_messages instead of throwing', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-text-max-messages');

        $response = Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withPrompt('Explain everything')
            ->asText();

        expect($response->finishReason)->toBe(FinishReason::Length)
            ->and($response->text)->toBe('Here is the first part of the answer.');
    });

    it('maps a steered response to Other', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-text-steered');

        $response = Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withPrompt('Explain everything')
            ->asText();

        expect($response->finishReason)->toBe(FinishReason::Other)
            ->and($response->text)->toBe('Switching to the new request.');
    });

    it('maps cache_write_tokens to cacheWriteInputTokens', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-text-cache-write');

        $response = Prism::text()
            ->using('openai', 'gpt-6-luna')
            ->withPrompt('A long prompt')
            ->asText();

        expect($response->usage->promptTokens)->toBe(3)
            ->and($response->usage->cacheWriteInputTokens)->toBe(2649)
            ->and($response->usage->cacheReadInputTokens)->toBe(0)
            ->and($response->steps[0]->usage->cacheWriteInputTokens)->toBe(2649);
    });
    it('stores a refused turn without the function call it never executed', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-text-refusal-tool-call');

        $calls = 0;

        $response = Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withTools([openAIWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withPrompt('Weather in Detroit?')
            ->asText();

        expect($response->finishReason)->toBe(FinishReason::Refusal)
            ->and($response->toolCalls)->toBe([])
            ->and(array_column($response->steps->sole()->raw['output'], 'type'))->toBe(['reasoning', 'function_call', 'message'])
            ->and(array_column($response->additionalContent['output_items'], 'type'))->toBe(['message']);

        Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withTools([openAIWeatherTool($calls)])
            ->withMessages([...unserialize(serialize($response->messages->all())), new UserMessage('Something else then.')])
            ->asText();

        $input = openAIRequestBodies()[1]['input'];

        expect($calls)->toBe(0)
            ->and(openAIUnansweredCalls($input))->toBe([])
            ->and(openAIInputShape(openAIRequestBodies()[1]))->toBe([
                'message:user',
                'message:assistant',
                'message:user',
            ])
            ->and($input[1]['content'])->toBe([['type' => 'refusal', 'refusal' => 'I can’t help with that request.']]);
    });

    it('stores a filtered turn that only called a function as nothing to replay', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-text-content-filter-tool-call');

        $calls = 0;

        $response = Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withTools([openAIWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withPrompt('Weather in Detroit?')
            ->asText();

        expect($response->finishReason)->toBe(FinishReason::ContentFilter)
            ->and($response->toolCalls)->toBe([])
            ->and($response->additionalContent)->not->toHaveKey('output_items');

        Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withTools([openAIWeatherTool($calls)])
            ->withMessages([...$response->messages, new UserMessage('Something else then.')])
            ->asText();

        expect($calls)->toBe(0)
            ->and(openAIInputShape(openAIRequestBodies()[1]))->toBe(['message:user', 'message:user']);
    });
});

describe('structured output items', function (): void {
    it('uses a strict json_schema format by default and decodes the final answer after commentary', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-structured-commentary');

        $response = Prism::structured()
            ->using('openai', 'gpt-6.1-sol')
            ->withSchema(openAIReportSchema())
            ->withPrompt('Weather report for Chicago')
            ->asStructured();

        expect($response->structured)->toBe(['city' => 'Chicago', 'temperature_c' => 18, 'summary' => 'Cloudy and mild.'])
            ->and($response->finishReason)->toBe(FinishReason::Stop)
            ->and($response->additionalContent['phase'])->toBe('final_answer');

        $body = openAIRequestBodies()[0];

        expect($body['text']['format']['type'])->toBe('json_schema')
            ->and($body['text']['format']['name'])->toBe('report')
            ->and(array_column($body['input'], 'role'))->toBe(['user']);
    });

    it('replays commentary and calls in order across structured tool steps', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-structured-tools');

        $calls = 0;

        $response = Prism::structured()
            ->using('openai', 'gpt-6.1-sol')
            ->withSchema(openAIReportSchema())
            ->withTools([openAIWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withProviderOptions(['store' => false])
            ->withPrompt('Weather report for Chicago')
            ->asStructured();

        expect($calls)->toBe(1)
            ->and($response->structured['city'])->toBe('Chicago')
            ->and($response->steps[0]->text)->toBe('I’ll check Chicago’s weather first.'."\n")
            ->and($response->steps[0]->additionalContent['phase'])->toBe('commentary');

        $bodies = openAIRequestBodies();

        expect(array_column($bodies, 'store'))->toBe([false, false])
            ->and(array_map(fn (array $body): string => $body['text']['format']['type'], $bodies))->toBe(['json_schema', 'json_schema'])
            ->and(openAIInputShape($bodies[1]))->toBe([
                'message:user',
                'reasoning:rs_0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d',
                'message:assistant',
                'function_call:fc_0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d',
                'function_call_output:call_Q2mY8sLx3Vt7Rk1Wb9Nc4Hd6',
            ])
            ->and($bodies[1]['input'][1]['summary'])->toBe([])
            ->and($bodies[1]['input'][2]['phase'])->toBe('commentary');
    });

    it('returns a refusal response instead of throwing', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-structured-refusal');

        $response = Prism::structured()
            ->using('openai', 'gpt-6.1-sol')
            ->withSchema(openAIReportSchema())
            ->withPrompt('Something the model refuses')
            ->asStructured();

        expect($response->finishReason)->toBe(FinishReason::Refusal)
            ->and($response->structured)->toBe([])
            ->and($response->text)->toBe('')
            ->and($response->additionalContent['refusal'])->toBe('I can’t help with that request.');
    });

    it('returns partial output for max_messages without strict decoding', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-structured-max-messages');

        $response = Prism::structured()
            ->using('openai', 'gpt-6.1-sol')
            ->withSchema(openAIReportSchema())
            ->withPrompt('Weather report for Chicago')
            ->asStructured();

        expect($response->finishReason)->toBe(FinishReason::Length)
            ->and($response->structured)->toBe([])
            ->and($response->text)->toBe('{"city":"Chi');
    });

    it('sends json_schema when structured mode is explicit, whatever the model name', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-structured-commentary');

        Prism::structured()
            ->using('openai', 'gpt-3.5-turbo')
            ->withSchema(openAIReportSchema())
            ->usingStructuredMode(StructuredMode::Structured)
            ->withPrompt('Weather report for Chicago')
            ->asStructured();

        Http::assertSent(fn (Request $request): bool => $request->data()['text']['format']['type'] === 'json_schema');
    });
    it('stores a refused turn without the function call it never executed', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-structured-refusal-tool-call');

        $calls = 0;

        $response = Prism::structured()
            ->using('openai', 'gpt-6.1-sol')
            ->withSchema(openAIReportSchema())
            ->withTools([openAIWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withPrompt('Weather report for Detroit')
            ->asStructured();

        $persisted = $response->steps->last()->messages;
        $assistant = end($persisted);

        expect($response->finishReason)->toBe(FinishReason::Refusal)
            ->and($assistant)->toBeInstanceOf(AssistantMessage::class)
            ->and($assistant->toolCalls)->toBe([])
            ->and(array_column($assistant->additionalContent['output_items'], 'type'))->toBe(['message']);

        Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withMessages([...$persisted, new UserMessage('Something else then.')])
            ->asText();

        $input = openAIRequestBodies()[1]['input'];

        expect($calls)->toBe(0)
            ->and(openAIUnansweredCalls($input))->toBe([])
            ->and(array_column($input, 'type'))->not->toContain('function_call');
    });
});

describe('stream output items and terminal states', function (): void {
    it('forwards store and replays the completed items in order on the follow-up stream request', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-stream-commentary-tools');

        $calls = 0;

        $events = collect(iterator_to_array(Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withTools([openAIWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withProviderOptions(['store' => false])
            ->withPrompt('Compare the weather in Detroit and Chicago.')
            ->asStream(), false));

        $stepFinishes = $events->whereInstanceOf(StepFinishEvent::class)->values();
        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class)->values();

        expect($calls)->toBe(2)
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds[0]->finishReason)->toBe(FinishReason::Stop)
            ->and($streamEnds[0]->usage->cacheWriteInputTokens)->toBe(192)
            ->and($streamEnds[0]->usage->cacheReadInputTokens)->toBe(1152 + 1344)
            ->and($streamEnds[0]->additionalContent)->not->toHaveKey('output_items')
            ->and($stepFinishes)->toHaveCount(2)
            ->and($stepFinishes[0]->additionalContent['phase'])->toBe('commentary')
            ->and(array_column($stepFinishes[0]->additionalContent['output_items'], 'type'))->toBe(['reasoning', 'message', 'function_call', 'function_call'])
            ->and($stepFinishes[1]->additionalContent['phase'])->toBe('final_answer')
            ->and(array_column($stepFinishes[1]->additionalContent['output_items'], 'type'))->toBe(['message']);

        $bodies = openAIRequestBodies();
        $replayed = $bodies[1]['input'];

        expect(array_column($bodies, 'store'))->toBe([false, false])
            ->and(openAIInputShape($bodies[1]))->toBe([
                'message:user',
                'reasoning:rs_0b3543181d883d0b016ad120dc11aa87d1a6c673200d2627b4',
                'message:assistant',
                'function_call:fc_0b3543181d883d0b016ad120dda4ac87d1a2d719a9c8df03ef',
                'function_call:fc_0b3543181d883d0b016ad120dda4c087d1a3b750b07e508b32',
                'function_call_output:call_9XZrHVWeYdSdyLpGJczIok1d',
                'function_call_output:call_LELisaCXtPpRoKvbM4434ouZ',
            ])
            ->and($replayed[1]['encrypted_content'])->toBe('gAAAAABq0SEttkngvqISYuHm-DONE-complete')
            ->and($replayed[2]['phase'])->toBe('commentary')
            ->and($replayed[2]['content'][0]['text'])->toBe("I’ll check the weather in Detroit and Chicago, then compare them.\n");
    });

    it('does not send store when it is not configured', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/stream-basic-text-responses');

        iterator_to_array(Prism::text()->using('openai', 'gpt-4o')->withPrompt('Who are you?')->asStream(), false);

        expect(openAIRequestBodies()[0])->not->toHaveKey('store');
    });

    it('classifies a refusal once and never runs the tool calls of a refused turn', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-stream-refusal');

        $calls = 0;

        $events = collect(iterator_to_array(Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withTools([openAIWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withPrompt('Something the model refuses')
            ->asStream(), false));

        $streamEnds = $events->whereInstanceOf(StreamEndEvent::class)->values();
        $stepFinish = $events->whereInstanceOf(StepFinishEvent::class)->sole();

        expect($calls)->toBe(0)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty()
            ->and($streamEnds)->toHaveCount(1)
            ->and($streamEnds[0]->finishReason)->toBe(FinishReason::Refusal)
            ->and($streamEnds[0]->additionalContent['refusal'])->toBe('I can’t help with that request.')
            ->and($stepFinish->additionalContent['refusal'])->toBe('I can’t help with that request.')
            ->and(array_column($stepFinish->additionalContent['output_items'], 'type'))->toBe(['message']);

        Http::assertSentCount(1);
    });

    it('ends an incomplete stream with Length, its usage, and no tool execution', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-stream-incomplete');

        $calls = 0;

        $events = collect(iterator_to_array(Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withTools([openAIWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withPrompt('Weather in Detroit?')
            ->asStream(), false));

        $streamEnd = $events->whereInstanceOf(StreamEndEvent::class)->sole();

        expect($calls)->toBe(0)
            ->and($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty()
            ->and($streamEnd->finishReason)->toBe(FinishReason::Length)
            ->and($streamEnd->usage->promptTokens)->toBe(58)
            ->and($streamEnd->usage->completionTokens)->toBe(16)
            ->and($streamEnd->usage->thoughtTokens)->toBe(9)
            ->and($streamEnd->additionalContent['status'])->toBe('incomplete')
            ->and($streamEnd->additionalContent['incomplete_details'])->toBe(['reason' => 'max_output_tokens']);

        Http::assertSentCount(1);
    });

    it('ends a failed stream with an error event, Error, and no tool execution', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-stream-failed');

        $calls = 0;

        $events = collect(iterator_to_array(Prism::text()
            ->using('openai', 'gpt-6.1-sol')
            ->withTools([openAIWeatherTool($calls)])
            ->withMaxSteps(3)
            ->withPrompt('Weather in Detroit?')
            ->asStream(), false));

        $error = $events->whereInstanceOf(ErrorEvent::class)->sole();
        $streamEnd = $events->whereInstanceOf(StreamEndEvent::class)->sole();

        expect($calls)->toBe(0)
            ->and($error->errorType)->toBe('server_error')
            ->and($error->message)->toBe('The model failed to generate a response.')
            ->and($error->recoverable)->toBeFalse()
            ->and($streamEnd->finishReason)->toBe(FinishReason::Error)
            ->and($streamEnd->additionalContent['status'])->toBe('failed');

        Http::assertSentCount(1);
    });

    it('persists a terminal turn without the function call it never executed', function (string $fixture): void {
        FixtureResponse::fakeResponseSequence('v1/responses', $fixture);

        $calls = 0;
        $persisted = openAIPersistedStreamTurn('Weather in Detroit?', $calls);

        $assistants = array_values(array_filter($persisted, fn (Message $message): bool => $message instanceof AssistantMessage));

        expect(array_merge(...array_map(fn (AssistantMessage $message): array => $message->toolCalls, $assistants)))->toBe([]);

        $input = (new MessageMap([
            new UserMessage('Weather in Detroit?'),
            ...$persisted,
            new UserMessage('Never mind.'),
        ], []))();

        expect($calls)->toBe(0)
            ->and(openAIUnansweredCalls($input))->toBe([])
            ->and(array_column($input, 'type'))->not->toContain('function_call')
            ->and($input[0]['role'])->toBe('user')
            ->and(end($input)['role'])->toBe('user');
    })->with([
        'refusal' => 'openai/gpt6-stream-refusal',
        'incomplete' => 'openai/gpt6-stream-incomplete',
        'failed' => 'openai/gpt6-stream-failed',
    ]);

    it('keeps every function call of a step that ended in tool calls, each answered on replay', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/gpt6-stream-commentary-tools');

        $calls = 0;
        $persisted = openAIPersistedStreamTurn('Weather in Detroit and Chicago?', $calls);

        $input = (new MessageMap([new UserMessage('Weather in Detroit and Chicago?'), ...$persisted], []))();

        expect($calls)->toBeGreaterThan(0)
            ->and(array_column($input, 'type'))->toContain('function_call')
            ->and(openAIUnansweredCalls($input))->toBe([]);
    });
});

/**
 * @return array<string, mixed>
 */
function openAICachedUsage(): array
{
    return [
        'input_tokens' => 5000,
        'input_tokens_details' => ['cached_tokens' => 3000, 'cache_write_tokens' => 1500],
        'output_tokens' => 400,
        'output_tokens_details' => ['reasoning_tokens' => 250],
        'total_tokens' => 5400,
    ];
}

/**
 * @param  array<string, mixed>  $usage
 */
function openAIFixtureWithUsage(string $fixture, array $usage): string
{
    $contents = (string) file_get_contents(__DIR__."/../../Fixtures/openai/{$fixture}");

    if (! str_starts_with($contents, 'event:')) {
        return (string) json_encode([...json_decode($contents, true), 'usage' => $usage]);
    }

    return (string) preg_replace_callback('/^data: (.+)$/m', function (array $line) use ($usage): string {
        $event = json_decode($line[1], true);

        if (in_array($event['type'], ['response.completed', 'response.incomplete'], true)) {
            $event['response']['usage'] = $usage;
        }

        return 'data: '.json_encode($event);
    }, $contents);
}

/**
 * @param  array<int, array<string, mixed>>  $usages
 */
function fakeOpenAIResponses(string $fixture, array $usages): void
{
    Http::fake([
        'v1/responses' => Http::sequence(array_map(
            fn (array $usage) => Http::response(openAIFixtureWithUsage($fixture, $usage)),
            $usages
        )),
    ])->preventStrayRequests();
}

function openAIUsageFrom(string $path): Usage
{
    return match ($path) {
        'text' => Prism::text()->using('openai', 'gpt-6.1-sol')->withPrompt('A long prompt')->asText()->usage,
        'structured' => Prism::structured()
            ->using('openai', 'gpt-6.1-sol')
            ->withSchema(openAIReportSchema())
            ->withPrompt('Weather report for Chicago')
            ->asStructured()
            ->usage,
        'stream' => collect(iterator_to_array(Prism::text()->using('openai', 'gpt-6.1-sol')->withPrompt('A long prompt')->asStream(), false))
            ->whereInstanceOf(StreamEndEvent::class)
            ->sole()
            ->usage,
    };
}

describe('usage buckets', function (): void {
    it('splits input tokens into the same disjoint buckets for every path', function (string $fixture, string $path): void {
        fakeOpenAIResponses($fixture, [openAICachedUsage()]);

        expect(openAIUsageFrom($path)->toArray())->toBe([
            'prompt_tokens' => 500,
            'completion_tokens' => 400,
            'cache_write_input_tokens' => 1500,
            'cache_read_input_tokens' => 3000,
            'thought_tokens' => 250,
        ]);
    })->with([
        'text' => ['gpt6-text-cache-write-1.json', 'text'],
        'structured' => ['gpt6-structured-commentary-1.json', 'structured'],
        'completed stream' => ['stream-basic-text-responses-1.json', 'stream'],
        'incomplete stream' => ['gpt6-stream-incomplete-1.json', 'stream'],
    ]);

    it('treats missing cache details as zero and never reports a negative prompt', function (): void {
        fakeOpenAIResponses('gpt6-text-cache-write-1.json', [
            [
                'input_tokens' => 10,
                'input_tokens_details' => ['cached_tokens' => 8, 'cache_write_tokens' => 5],
                'output_tokens' => 2,
            ],
            ['input_tokens' => 10, 'output_tokens' => 2],
        ]);

        $inconsistent = openAIUsageFrom('text');
        $withoutDetails = openAIUsageFrom('text');

        expect($inconsistent->promptTokens)->toBe(0)
            ->and($withoutDetails->promptTokens)->toBe(10)
            ->and($withoutDetails->cacheReadInputTokens)->toBeNull()
            ->and($withoutDetails->cacheWriteInputTokens)->toBeNull()
            ->and($withoutDetails->thoughtTokens)->toBeNull();
    });
});
