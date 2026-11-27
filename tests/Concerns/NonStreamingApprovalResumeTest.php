<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;

/*
 * G-352. A non-streaming text or structured run that stops for an approval
 * must hand the pending approval requests back on its response, in the shape
 * the Anthropic handler uses: on the last step, and (for text) on the
 * assistant message in $response->messages. Most handlers left them off the
 * step, so the documented resume found no approval request, denied every
 * approval by default, and the approved tool never ran.
 *
 * Driven through Prism::text() and Prism::structured() over faked HTTP, for
 * every provider whose non-streaming handler can stop for an approval.
 * Anthropic and OpenAI Responses are the reference and stay in the list.
 */

function g352ChatCompletionsBody(string $id, bool $toolCall, string $content = ''): array
{
    return [
        'id' => $id,
        'object' => 'chat.completion',
        'created' => 1,
        'model' => 'test-model',
        'choices' => [[
            'index' => 0,
            'message' => $toolCall
                ? ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
                    'id' => 'call_g352',
                    'type' => 'function',
                    'function' => ['name' => 'delete_file', 'arguments' => '{"path":"a.txt"}'],
                ]]]
                : ['role' => 'assistant', 'content' => $content],
            'finish_reason' => $toolCall ? 'tool_calls' : 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ];
}

function g352QwenBody(bool $toolCall, string $content = ''): array
{
    $body = g352ChatCompletionsBody('q', $toolCall, $content);

    return [
        'request_id' => 'q',
        'output' => ['choices' => $body['choices']],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
    ];
}

function g352OllamaBody(bool $toolCall, string $content = ''): array
{
    return [
        'model' => 'test-model',
        'created_at' => '2026-01-01T00:00:00Z',
        'message' => $toolCall
            ? ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'delete_file', 'arguments' => ['path' => 'a.txt']]]]]
            : ['role' => 'assistant', 'content' => $content],
        'done_reason' => 'stop',
        'done' => true,
        'prompt_eval_count' => 1,
        'eval_count' => 1,
    ];
}

function g352GeminiBody(bool $toolCall, string $content = ''): array
{
    return [
        'candidates' => [[
            'content' => [
                'parts' => [$toolCall ? ['functionCall' => ['name' => 'delete_file', 'args' => ['path' => 'a.txt']]] : ['text' => $content]],
                'role' => 'model',
            ],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
        'modelVersion' => 'test-model',
    ];
}

function g352AnthropicBody(bool $toolCall, string $content = ''): array
{
    return [
        'id' => 'msg_g352',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'test-model',
        'content' => $toolCall
            ? [['type' => 'tool_use', 'id' => 'toolu_g352', 'name' => 'delete_file', 'input' => ['path' => 'a.txt']]]
            : [['type' => 'text', 'text' => $content]],
        'stop_reason' => $toolCall ? 'tool_use' : 'end_turn',
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ];
}

function g352ResponsesBody(bool $toolCall, string $content = ''): array
{
    return [
        'id' => 'resp_g352',
        'object' => 'response',
        'created_at' => 1,
        'status' => 'completed',
        'model' => 'test-model',
        'output' => $toolCall
            ? [['id' => 'fc_g352', 'type' => 'function_call', 'status' => 'completed', 'call_id' => 'call_g352', 'name' => 'delete_file', 'arguments' => '{"path":"a.txt"}']]
            : [['id' => 'msg_g352', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $content, 'annotations' => []]]]],
        'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2],
    ];
}

/**
 * provider => [provider key, configuration, body(bool $toolCall, string $content)]
 *
 * @return array<string, array{0: string, 1: array<string, mixed>, 2: Closure(bool, string): array<string, mixed>}>
 */
function g352Providers(): array
{
    $chat = fn (bool $toolCall, string $content): array => g352ChatCompletionsBody('chat', $toolCall, $content);

    return [
        'anthropic (reference)' => ['anthropic', ['api_key' => 'not-a-key'], g352AnthropicBody(...)],
        'openai responses (reference)' => ['openai', ['api_key' => 'not-a-key', 'api_format' => 'responses'], g352ResponsesBody(...)],
        'openai chat completions' => ['openai', ['api_key' => 'not-a-key', 'api_format' => 'chat_completions'], $chat],
        'azure' => ['azure', ['url' => 'https://g352.invalid', 'api_key' => 'not-a-key', 'api_version' => '2024-10-21', 'deployment_name' => 'test-model'], $chat],
        'deepseek' => ['deepseek', ['api_key' => 'not-a-key'], $chat],
        'gemini' => ['gemini', ['api_key' => 'not-a-key'], g352GeminiBody(...)],
        'groq' => ['groq', ['api_key' => 'not-a-key'], $chat],
        'mistral' => ['mistral', ['api_key' => 'not-a-key'], $chat],
        'ollama' => ['ollama', ['url' => 'http://g352.invalid'], g352OllamaBody(...)],
        'openrouter' => ['openrouter', ['api_key' => 'not-a-key'], $chat],
        'qwen' => ['qwen', ['api_key' => 'not-a-key'], g352QwenBody(...)],
        'requesty' => ['requesty', ['api_key' => 'not-a-key'], $chat],
        'vertex' => ['vertex', ['project_id' => 'g352', 'region' => 'us-central1', 'access_token' => 'not-a-token'], g352GeminiBody(...)],
        'xai' => ['xai', ['api_key' => 'not-a-key'], $chat],
        'z' => ['z', ['api_key' => 'not-a-key'], $chat],
    ];
}

/**
 * Structured handlers that run custom tools without streaming.
 *
 * @return array<string, array{0: string, 1: array<string, mixed>, 2: Closure(bool, string): array<string, mixed>}>
 */
function g352StructuredProviders(): array
{
    return array_intersect_key(g352Providers(), array_flip([
        'anthropic (reference)',
        'openai responses (reference)',
        'gemini',
        'mistral',
        'openrouter',
        'requesty',
        'vertex',
    ]));
}

/**
 * Fake one tool-call reply, then the same final reply for every later request.
 *
 * @param  Closure(bool, string): array<string, mixed>  $body
 */
function g352Fake(Closure $body, string $final): void
{
    Http::preventStrayRequests();
    Http::fakeSequence()
        ->push($body(true, ''))
        ->whenEmpty(Http::response($body(false, $final)));
}

/**
 * @param  array<string, mixed>  $config
 */
function g352Configure(string $provider, array $config): void
{
    foreach ($config as $key => $value) {
        config()->set("prism.providers.{$provider}.{$key}", $value);
    }
}

function g352Tool(int &$ran): \Prism\Prism\Tool
{
    return Tool::as('delete_file')->for('Delete a file')->withStringParameter('path', 'Path')
        ->using(function (string $path) use (&$ran): string {
            $ran++;

            return "deleted {$path}";
        })
        ->requiresApproval();
}

function g352Schema(): ObjectSchema
{
    return new ObjectSchema('answer', 'The answer', [new StringSchema('answer', 'The answer')], ['answer']);
}

dataset('g352 approval decisions', [
    'approved' => [true],
    'denied' => [false],
]);

it('hands back the pending approval on a non-streaming text response, and resumes it', function (string $provider, array $config, Closure $body, bool $approved): void {
    g352Configure($provider, $config);
    g352Fake($body, 'Done.');
    $ran = 0;

    $response = Prism::text()->using($provider, 'test-model')->withTools([g352Tool($ran)])->withMaxSteps(3)
        ->withPrompt('Delete a.txt')->asText();

    $step = $response->steps->last();

    // Not $response->finishReason: Ollama reports a tool-call step as `stop`.
    expect($ran)->toBe(0)
        ->and($step->toolApprovalRequests)->toHaveCount(1)
        ->and($step->toolApprovalRequests[0]->toolCallId)->toBe($step->toolCalls[0]->id)
        ->and($step->toolApprovalRequests[0]->argumentsFormat)->toBe(ToolApprovalRequest::ARGUMENTS_AS_SENT)
        ->and($response->messages->last(fn ($message): bool => $message instanceof AssistantMessage)->toolApprovalRequests)
        ->toEqual($step->toolApprovalRequests);

    $request = $step->toolApprovalRequests[0];

    Prism::text()->using($provider, 'test-model')->withTools([g352Tool($ran)])->withMaxSteps(3)
        ->withMessages([...$response->messages, new ToolResultMessage([], [
            new ToolApprovalResponse($request->approvalId, $approved, $approved ? null : 'Not today'),
        ])])
        ->asText();

    expect($ran)->toBe($approved ? 1 : 0);
})->with(g352Providers())->with('g352 approval decisions');

it('hands back the pending approval on a non-streaming structured response, and resumes it', function (string $provider, array $config, Closure $body, bool $approved): void {
    g352Configure($provider, $config);
    g352Fake($body, '{"answer":"done"}');
    $ran = 0;

    $response = Prism::structured()->using($provider, 'test-model')->withSchema(g352Schema())->withTools([g352Tool($ran)])->withMaxSteps(3)
        ->withPrompt('Delete a.txt')->asStructured();

    $step = $response->steps->last();

    expect($ran)->toBe(0)
        ->and($step->toolApprovalRequests)->toHaveCount(1)
        ->and($step->toolApprovalRequests[0]->toolCallId)->toBe($step->toolCalls[0]->id ?? null)
        ->and($step->toolApprovalRequests[0]->argumentsFormat)->toBe(ToolApprovalRequest::ARGUMENTS_AS_SENT);

    $request = $step->toolApprovalRequests[0];

    // The structured response has no message list; the step's messages are
    // the conversation the request carried, ending with the stopped step.
    Prism::structured()->using($provider, 'test-model')->withSchema(g352Schema())->withTools([g352Tool($ran)])->withMaxSteps(3)
        ->withMessages([...$step->messages, new ToolResultMessage([], [
            new ToolApprovalResponse($request->approvalId, $approved, $approved ? null : 'Not today'),
        ])])
        ->asStructured();

    expect($ran)->toBe($approved ? 1 : 0);
})->with(g352StructuredProviders())->with('g352 approval decisions');

it('leaves no non-streaming handler that can stop for an approval without handing it back', function (): void {
    // A provider added later is not in the lists above. Any non-streaming
    // handler that can stop for an approval must put the requests on its Step.
    $root = dirname(__DIR__, 2);

    $handlers = collect(File::allFiles($root.'/src/Providers'))
        ->filter(fn (SplFileInfo $file): bool => in_array($file->getFilename(), ['Text.php', 'Structured.php'], true))
        ->mapWithKeys(fn (SplFileInfo $file): array => [
            str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1)) => (string) file_get_contents($file->getPathname()),
        ])
        ->filter(fn (string $source): bool => str_contains($source, 'callToolsWithPending('));

    $without = $handlers
        ->reject(fn (string $source): bool => str_contains($source, 'toolApprovalRequests: $this->approvalRequestsForStep()')
            || str_contains($source, 'toolApprovalRequests: $toolApprovalRequests'))
        ->keys()
        ->all();

    // 18 fixed here, plus Anthropic and OpenAI Responses text and structured.
    expect($handlers)->toHaveCount(22)
        ->and($without)->toBe([]);
});
