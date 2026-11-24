<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\FixtureResponse;

/*
 * G-355. A text response's messages are the conversation as the request
 * carried it, with each assistant message once. Azure and Z recorded their
 * steps after adding the assistant message (and tool results) to the
 * request, and the response builder then appended the step's assistant
 * message again. A caller resuming or continuing from $response->messages
 * sent the tool call twice on every later request.
 *
 * Driven through Prism::text() over faked HTTP for every provider whose
 * non-streaming text handler runs tools. The tool call's argument, `a.txt`,
 * appears nowhere else in a request (not in the prompt, the tool definition
 * or the tool result), so counting it counts how often the call is carried.
 */

function g355ChatCompletionsBody(bool $toolCall, string $content = ''): array
{
    return [
        'id' => 'chat',
        'object' => 'chat.completion',
        'created' => 1,
        'model' => 'test-model',
        'choices' => [[
            'index' => 0,
            'message' => $toolCall
                ? ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
                    'id' => 'call_g355',
                    'type' => 'function',
                    'function' => ['name' => 'remove_file', 'arguments' => '{"path":"a.txt"}'],
                ]]]
                : ['role' => 'assistant', 'content' => $content],
            'finish_reason' => $toolCall ? 'tool_calls' : 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ];
}

function g355QwenBody(bool $toolCall, string $content = ''): array
{
    $body = g355ChatCompletionsBody($toolCall, $content);

    return [
        'request_id' => 'q',
        'output' => ['choices' => $body['choices']],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
    ];
}

function g355OllamaBody(bool $toolCall, string $content = ''): array
{
    return [
        'model' => 'test-model',
        'created_at' => '2026-01-01T00:00:00Z',
        'message' => $toolCall
            ? ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'remove_file', 'arguments' => ['path' => 'a.txt']]]]]
            : ['role' => 'assistant', 'content' => $content],
        'done_reason' => 'stop',
        'done' => true,
        'prompt_eval_count' => 1,
        'eval_count' => 1,
    ];
}

function g355GeminiBody(bool $toolCall, string $content = ''): array
{
    return [
        'candidates' => [[
            'content' => [
                'parts' => [$toolCall ? ['functionCall' => ['name' => 'remove_file', 'args' => ['path' => 'a.txt']]] : ['text' => $content]],
                'role' => 'model',
            ],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
        'modelVersion' => 'test-model',
    ];
}

function g355AnthropicBody(bool $toolCall, string $content = ''): array
{
    return [
        'id' => 'msg_g355',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'test-model',
        'content' => $toolCall
            ? [['type' => 'tool_use', 'id' => 'toolu_g355', 'name' => 'remove_file', 'input' => ['path' => 'a.txt']]]
            : [['type' => 'text', 'text' => $content]],
        'stop_reason' => $toolCall ? 'tool_use' : 'end_turn',
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ];
}

function g355ResponsesBody(bool $toolCall, string $content = ''): array
{
    return [
        'id' => 'resp_g355',
        'object' => 'response',
        'created_at' => 1,
        'status' => 'completed',
        'model' => 'test-model',
        'output' => $toolCall
            ? [['id' => 'fc_g355', 'type' => 'function_call', 'status' => 'completed', 'call_id' => 'call_g355', 'name' => 'remove_file', 'arguments' => '{"path":"a.txt"}']]
            : [['id' => 'msg_g355', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $content, 'annotations' => []]]]],
        'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2],
    ];
}

/**
 * provider => [provider key, configuration, body(bool $toolCall, string $content)]
 *
 * @return array<string, array{0: string, 1: array<string, mixed>, 2: Closure(bool, string): array<string, mixed>}>
 */
function g355Providers(): array
{
    return [
        'anthropic' => ['anthropic', ['api_key' => 'not-a-key'], g355AnthropicBody(...)],
        'openai responses' => ['openai', ['api_key' => 'not-a-key', 'api_format' => 'responses'], g355ResponsesBody(...)],
        'openai chat completions' => ['openai', ['api_key' => 'not-a-key', 'api_format' => 'chat_completions'], g355ChatCompletionsBody(...)],
        'azure' => ['azure', ['url' => 'https://g355.invalid', 'api_key' => 'not-a-key', 'api_version' => '2024-10-21', 'deployment_name' => 'test-model'], g355ChatCompletionsBody(...)],
        'deepseek' => ['deepseek', ['api_key' => 'not-a-key'], g355ChatCompletionsBody(...)],
        'gemini' => ['gemini', ['api_key' => 'not-a-key'], g355GeminiBody(...)],
        'groq' => ['groq', ['api_key' => 'not-a-key'], g355ChatCompletionsBody(...)],
        'mistral' => ['mistral', ['api_key' => 'not-a-key'], g355ChatCompletionsBody(...)],
        'ollama' => ['ollama', ['url' => 'http://g355.invalid'], g355OllamaBody(...)],
        'openrouter' => ['openrouter', ['api_key' => 'not-a-key'], g355ChatCompletionsBody(...)],
        'qwen' => ['qwen', ['api_key' => 'not-a-key'], g355QwenBody(...)],
        'requesty' => ['requesty', ['api_key' => 'not-a-key'], g355ChatCompletionsBody(...)],
        'vertex' => ['vertex', ['project_id' => 'g355', 'region' => 'us-central1', 'access_token' => 'not-a-token'], g355GeminiBody(...)],
        'xai' => ['xai', ['api_key' => 'not-a-key'], g355ChatCompletionsBody(...)],
        'z' => ['z', ['api_key' => 'not-a-key'], g355ChatCompletionsBody(...)],
    ];
}

/**
 * Fake one tool-call reply, then the same final reply for every later request,
 * and record every request body that was sent.
 *
 * @param  Closure(bool, string): array<string, mixed>  $body
 * @param  array<int, string>  $sent
 * @param  array<string, mixed>  $config
 */
function g355Fake(string $provider, array $config, Closure $body, array &$sent): void
{
    foreach ($config as $key => $value) {
        config()->set("prism.providers.{$provider}.{$key}", $value);
    }

    Http::preventStrayRequests();

    $replies = [$body(true, ''), $body(false, 'Done.')];

    Http::fake(function (HttpRequest $request) use (&$sent, &$replies): PromiseInterface {
        $sent[] = $request->body();

        return Http::response(count($replies) > 1 ? array_shift($replies) : $replies[0]);
    });
}

function g355Tool(bool $requiresApproval): \Prism\Prism\Tool
{
    $tool = Tool::as('remove_file')->for('Remove a file')->withStringParameter('path', 'Path')
        ->using(fn (string $path): string => 'ok');

    return $requiresApproval ? $tool->requiresApproval() : $tool;
}

/**
 * How many times a request body carries the tool call.
 */
function g355CallsCarried(string $body): int
{
    return substr_count($body, 'a.txt');
}

/**
 * @param  iterable<Message>  $messages
 * @return array<int, string>
 */
function g355Shape(iterable $messages): array
{
    $shape = [];

    foreach ($messages as $message) {
        $shape[] = match (true) {
            $message instanceof UserMessage => 'user',
            $message instanceof AssistantMessage => $message->toolCalls === [] ? 'assistant' : 'assistant(call)',
            $message instanceof ToolResultMessage => 'tool_result',
            default => $message::class,
        };
    }

    return $shape;
}

it('carries a tool call once when a run stops for approval and is resumed', function (string $provider, array $config, Closure $body): void {
    $sent = [];
    g355Fake($provider, $config, $body, $sent);

    $response = Prism::text()->using($provider, 'test-model')->withTools([g355Tool(true)])->withMaxSteps(3)
        ->withPrompt('Remove the file')->asText();

    expect(g355Shape($response->messages))->toBe(['user', 'assistant(call)']);

    $approval = $response->steps->last()->toolApprovalRequests[0];

    Prism::text()->using($provider, 'test-model')->withTools([g355Tool(true)])->withMaxSteps(3)
        ->withMessages([...$response->messages, new ToolResultMessage([], [
            new ToolApprovalResponse($approval->approvalId, true),
        ])])
        ->asText();

    // The first request has not seen the call yet; the resume carries it once.
    expect($sent)->toHaveCount(2)
        ->and(g355CallsCarried($sent[0]))->toBe(0)
        ->and(g355CallsCarried($sent[1]))->toBe(1);
})->with(g355Providers());

it('carries a tool call once in the next step and in a follow-up turn', function (string $provider, array $config, Closure $body): void {
    $sent = [];
    g355Fake($provider, $config, $body, $sent);

    $response = Prism::text()->using($provider, 'test-model')->withTools([g355Tool(false)])->withMaxSteps(3)
        ->withPrompt('Remove the file')->asText();

    expect($response->text)->toBe('Done.')
        ->and(g355Shape($response->messages))->toBe(['user', 'assistant(call)', 'tool_result', 'assistant']);

    Prism::text()->using($provider, 'test-model')->withTools([g355Tool(false)])->withMaxSteps(3)
        ->withMessages([...$response->messages, new UserMessage('Thanks')])
        ->asText();

    expect($sent)->toHaveCount(3)
        ->and(g355CallsCarried($sent[0]))->toBe(0)
        ->and(g355CallsCarried($sent[1]))->toBe(1)
        ->and(g355CallsCarried($sent[2]))->toBe(1)
        ->and(substr_count($sent[2], 'Done.'))->toBe(1);
})->with(g355Providers());

it('stores a Replicate reply once', function (): void {
    config()->set('prism.providers.replicate.api_key', 'r8_not-a-key');
    config()->set('prism.providers.replicate.polling_interval', 10);
    config()->set('prism.providers.replicate.max_wait_time', 10);
    Http::preventStrayRequests();
    FixtureResponse::fakeResponseSequence('*', 'replicate/generate-text-with-a-prompt');

    $response = Prism::text()->using('replicate', 'meta/meta-llama-3.1-405b-instruct')
        ->withPrompt('Hello, world!')->asText();

    expect(g355Shape($response->messages))->toBe(['user', 'assistant']);
});
