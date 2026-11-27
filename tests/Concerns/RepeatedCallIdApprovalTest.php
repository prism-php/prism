<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;

/*
 * Each tool call is decided by its own approval. A provider can return two
 * calls in one step with the same id (Gemini uses the function name as the
 * id), so the id cannot tell their approvals apart. A step whose call ids, or
 * whose approval requests' call ids, repeat is denied: no gated call in it
 * runs, whatever was decided.
 *
 * Driven through Prism::text(), Prism::structured() and asStream() over faked
 * HTTP, offline.
 */

const G392_REASON = 'Tool call was not run because another call in this step has the same id';

function g392GeminiTwoCalls(): array
{
    return [
        'candidates' => [[
            'content' => [
                'parts' => [
                    ['functionCall' => ['name' => 'weather', 'args' => ['city' => 'Paris']]],
                    ['functionCall' => ['name' => 'weather', 'args' => ['city' => 'Rome']]],
                ],
                'role' => 'model',
            ],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
        'modelVersion' => 'test-model',
    ];
}

function g392GeminiText(string $text): array
{
    return [
        'candidates' => [[
            'content' => ['parts' => [['text' => $text]], 'role' => 'model'],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
        'modelVersion' => 'test-model',
    ];
}

function g392ResponsesTwoCalls(string $firstId, string $secondId): array
{
    return [
        'id' => 'resp_g392',
        'object' => 'response',
        'created_at' => 1,
        'status' => 'completed',
        'model' => 'test-model',
        'output' => [
            ['id' => $firstId, 'type' => 'function_call', 'status' => 'completed', 'call_id' => $firstId, 'name' => 'weather', 'arguments' => '{"city":"Paris"}'],
            ['id' => $secondId, 'type' => 'function_call', 'status' => 'completed', 'call_id' => $secondId, 'name' => 'weather', 'arguments' => '{"city":"Rome"}'],
        ],
        'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2],
    ];
}

function g392ResponsesText(string $text): array
{
    return [
        'id' => 'resp_g392_final',
        'object' => 'response',
        'created_at' => 1,
        'status' => 'completed',
        'model' => 'test-model',
        'output' => [['id' => 'msg_g392', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]]],
        'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2],
    ];
}

/**
 * @param  list<string>  $ran
 */
function g392Tool(array &$ran): \Prism\Prism\Tool
{
    return Tool::as('weather')->for('Weather for a city')->withStringParameter('city', 'City')
        ->using(function (string $city) use (&$ran): string {
            $ran[] = $city;

            return "Sunny in {$city}";
        })
        ->requiresApproval();
}

function g392Schema(): ObjectSchema
{
    return new ObjectSchema('answer', 'The answer', [new StringSchema('answer', 'The answer')], ['answer']);
}

/**
 * @param  list<bool>  $decisions  one per approval request, in order
 * @param  list<ToolApprovalRequest>  $requests
 */
function g392Decisions(array $requests, array $decisions): ToolResultMessage
{
    return new ToolResultMessage([], array_map(
        fn (ToolApprovalRequest $request, bool $approved): ToolApprovalResponse => new ToolApprovalResponse($request->approvalId, $approved),
        $requests,
        $decisions,
    ));
}

/**
 * The result each call received on resume, by city, read from the tool
 * result message the resume added to the conversation.
 *
 * @param  array<int, mixed>  $messages
 * @return array<string, string>
 */
function g392ResultsByCity(array $messages): array
{
    $out = [];

    foreach ($messages as $message) {
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                $out[(string) ($result->args['city'] ?? '?')] = (string) $result->result;
            }
        }
    }

    ksort($out);

    return $out;
}

dataset('g392 mixed decisions', [
    'deny the first, approve the second' => [[false, true]],
    'approve the first, deny the second' => [[true, false]],
    'approve both' => [[true, true]],
]);

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', 'not-a-key');
    config()->set('prism.providers.openai.api_key', 'not-a-key');
    config()->set('prism.providers.openai.api_format', 'responses');
    Http::preventStrayRequests();
});

it('runs no gated call of a text step whose two calls share one id, whatever was decided', function (array $decisions): void {
    Http::fakeSequence()->push(g392GeminiTwoCalls())->whenEmpty(Http::response(g392GeminiText('Done.')));
    $ran = [];

    $response = Prism::text()->using('gemini', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withPrompt('Weather in Paris and Rome')->asText();

    $step = $response->steps->last();

    expect($ran)->toBe([])
        ->and($step->toolCalls)->toHaveCount(2)
        ->and($step->toolCalls[0]->id)->toBe($step->toolCalls[1]->id)
        ->and($step->toolApprovalRequests)->toHaveCount(2);

    $resumed = Prism::text()->using('gemini', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([...$response->messages, g392Decisions($step->toolApprovalRequests, $decisions)])
        ->asText();

    expect($ran)->toBe([])
        ->and(g392ResultsByCity($resumed->steps->first()->messages))->toBe(['Paris' => G392_REASON, 'Rome' => G392_REASON]);
})->with('g392 mixed decisions');

it('runs no gated call of a structured step whose two calls share one id, whatever was decided', function (array $decisions): void {
    Http::fakeSequence()->push(g392GeminiTwoCalls())->whenEmpty(Http::response(g392GeminiText('{"answer":"done"}')));
    $ran = [];

    $response = Prism::structured()->using('gemini', 'test-model')->withSchema(g392Schema())->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withPrompt('Weather in Paris and Rome')->asStructured();

    $step = $response->steps->last();

    expect($ran)->toBe([])
        ->and($step->toolApprovalRequests)->toHaveCount(2);

    Prism::structured()->using('gemini', 'test-model')->withSchema(g392Schema())->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([...$step->messages, g392Decisions($step->toolApprovalRequests, $decisions)])
        ->asStructured();

    expect($ran)->toBe([]);
})->with('g392 mixed decisions');

it('runs no gated call of a streamed resume whose two calls share one id, whatever was decided', function (array $decisions): void {
    Http::fake(['*' => Http::response("data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Done.\"}],\"role\":\"model\"},\"finishReason\":\"STOP\"}],\"usageMetadata\":{\"promptTokenCount\":1,\"candidatesTokenCount\":1,\"totalTokenCount\":2},\"modelVersion\":\"test-model\"}\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
    $ran = [];
    $paris = new ToolCall('weather', 'weather', ['city' => 'Paris']);
    $rome = new ToolCall('weather', 'weather', ['city' => 'Rome']);
    $requests = [
        new ToolApprovalRequest('apr_paris', 'weather', ToolApprovalRequest::ARGUMENTS_AS_SENT),
        new ToolApprovalRequest('apr_rome', 'weather', ToolApprovalRequest::ARGUMENTS_AS_SENT),
    ];

    $events = iterator_to_array(Prism::text()->using('gemini', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([
            new UserMessage('Weather in Paris and Rome'),
            new AssistantMessage('', [$paris, $rome], [], $requests),
            g392Decisions($requests, $decisions),
        ])->asStream(), false);

    expect($ran)->toBe([])
        ->and($events)->not->toBeEmpty();
})->with('g392 mixed decisions');

it('runs no gated call when two approval requests name one call id', function (): void {
    Http::fakeSequence()->whenEmpty(Http::response(g392GeminiText('Done.')));
    $ran = [];
    $paris = new ToolCall('weather', 'weather', ['city' => 'Paris']);
    $requests = [
        new ToolApprovalRequest('apr_one', 'weather', ToolApprovalRequest::ARGUMENTS_AS_SENT),
        new ToolApprovalRequest('apr_two', 'weather', ToolApprovalRequest::ARGUMENTS_AS_SENT),
    ];

    $resumed = Prism::text()->using('gemini', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([
            new UserMessage('Weather in Paris'),
            new AssistantMessage('', [$paris], [], $requests),
            g392Decisions($requests, [false, true]),
        ])->asText();

    expect($ran)->toBe([])
        ->and(g392ResultsByCity($resumed->steps->first()->messages))->toBe(['Paris' => G392_REASON]);
});

it('denies a repeated-id step once, and a later turn does not run it either', function (): void {
    Http::fakeSequence()->whenEmpty(Http::response(g392GeminiText('Done.')));
    $ran = [];
    $paris = new ToolCall('weather', 'weather', ['city' => 'Paris']);
    $rome = new ToolCall('weather', 'weather', ['city' => 'Rome']);
    $requests = [
        new ToolApprovalRequest('apr_paris', 'weather', ToolApprovalRequest::ARGUMENTS_AS_SENT),
        new ToolApprovalRequest('apr_rome', 'weather', ToolApprovalRequest::ARGUMENTS_AS_SENT),
    ];

    $first = Prism::text()->using('gemini', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([
            new UserMessage('Weather in Paris and Rome'),
            new AssistantMessage('', [$paris, $rome], [], $requests),
            g392Decisions($requests, [true, true]),
        ])->asText();

    Prism::text()->using('gemini', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([...$first->messages, new UserMessage('And again?')])->asText();

    expect($ran)->toBe([]);
});

it('still decides each call by its own approval when the ids are distinct', function (array $decisions): void {
    Http::fakeSequence()->push(g392ResponsesTwoCalls('call_paris', 'call_rome'))->whenEmpty(Http::response(g392ResponsesText('Done.')));
    $ran = [];

    $response = Prism::text()->using('openai', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withPrompt('Weather in Paris and Rome')->asText();

    $step = $response->steps->last();

    expect($ran)->toBe([])
        ->and($step->toolApprovalRequests)->toHaveCount(2)
        ->and(array_map(fn (ToolApprovalRequest $request): string => $request->toolCallId, $step->toolApprovalRequests))->toBe(['call_paris', 'call_rome']);

    Prism::text()->using('openai', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([...$response->messages, g392Decisions($step->toolApprovalRequests, $decisions)])
        ->asText();

    $expected = array_values(array_filter(['Paris', 'Rome'], fn (string $city, int $i): bool => $decisions[$i], ARRAY_FILTER_USE_BOTH));

    expect($ran)->toBe($expected);
})->with('g392 mixed decisions');

it('runs no gated call of an OpenAI Responses step whose two calls share one id and call_id', function (array $decisions): void {
    Http::fakeSequence()->push(g392ResponsesTwoCalls('call_same', 'call_same'))->whenEmpty(Http::response(g392ResponsesText('Done.')));
    $ran = [];

    $response = Prism::text()->using('openai', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withPrompt('Weather in Paris and Rome')->asText();

    $step = $response->steps->last();

    Prism::text()->using('openai', 'test-model')->withTools([g392Tool($ran)])->withMaxSteps(3)
        ->withMessages([...$response->messages, g392Decisions($step->toolApprovalRequests, $decisions)])
        ->asText();

    expect($ran)->toBe([]);
})->with('g392 mixed decisions');
