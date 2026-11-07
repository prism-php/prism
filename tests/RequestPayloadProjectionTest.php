<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Exceptions\PrismUnprojectableRequestException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Provider as BaseProvider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

it('projects the actual next body without sending or mutating its request', function (
    string $providerName,
    string $apiFormat,
    string $mode,
    StructuredMode $structuredMode,
    array $options,
): void {
    Http::preventStrayRequests();
    Http::fake();
    $generation = $mode === 'structured' ? Prism::structured() : Prism::text();
    $generation->using($providerName, 'gpt-4o', [
        'api_key' => 'projection-test-secret',
        'url' => 'https://projection.invalid/v1',
        'api_format' => $apiFormat,
    ])->withSystemPrompt('System π')->withPrompt('{"signature":"billed user text"}')
        ->withProviderOptions($options);

    if ($mode === 'structured') {
        $generation->withSchema(new ObjectSchema('answer', 'Answer', [new StringSchema('value', 'Value')], ['value']))
            ->usingStructuredMode($structuredMode);
    }

    $request = $generation->toRequest();
    $original = serialize($request);
    $provider = $generation->provider();
    $projection = $provider->projectPayload($request, $mode);

    expect($projection)->toBeArray()
        ->and(serialize($request))->toBe($original)
        ->and($provider->projectPayload($request, $mode))->toBe($projection)
        ->and(json_encode($projection))->not->toContain('projection-test-secret');
    Http::assertNothingSent();

    // Intercept the real sender at HTTP dispatch, before any response handling.
    $sent = null;
    $intercepted = new RuntimeException('Intercepted projection request');
    Http::fake(function (HttpRequest $wire) use (&$sent, $intercepted): never {
        $sent = $wire;
        throw $intercepted;
    });
    $caught = null;
    try {
        match ($mode) {
            'structured' => $provider->structured($request),
            'stream' => iterator_to_array($provider->stream($request)),
            default => $provider->text($request),
        };
    } catch (Throwable $error) {
        $caught = $error;
    }

    expect($caught)->toBe($intercepted)
        ->and($sent)->toBeInstanceOf(HttpRequest::class)
        ->and(json_decode($sent->body(), true, flags: JSON_THROW_ON_ERROR))->toBe($projection)
        ->and($sent->body())->toContain('billed user text');
})->with([
    'Anthropic text' => ['anthropic', 'responses', 'text', StructuredMode::Auto, []],
    'Anthropic stream' => ['anthropic', 'responses', 'stream', StructuredMode::Auto, []],
    'Anthropic native schema' => ['anthropic', 'responses', 'structured', StructuredMode::Auto, ['effort' => 'high']],
    'Anthropic tool schema' => ['anthropic', 'responses', 'structured', StructuredMode::Auto, ['use_tool_calling' => true]],
    'Anthropic tool schema thinking off' => ['anthropic', 'responses', 'structured', StructuredMode::Auto, ['use_tool_calling' => true, 'thinking' => ['enabled' => false]]],
    'Responses text' => ['openai', 'responses', 'text', StructuredMode::Auto, []],
    'Responses stream' => ['openai', 'responses', 'stream', StructuredMode::Auto, []],
    'Responses auto schema' => ['openai', 'responses', 'structured', StructuredMode::Auto, []],
    'Responses explicit schema' => ['openai', 'responses', 'structured', StructuredMode::Structured, ['schema' => ['strict' => false]]],
    'Responses JSON mode' => ['openai', 'responses', 'structured', StructuredMode::Json, []],
    'Chat text' => ['openai', 'chat_completions', 'text', StructuredMode::Auto, []],
    'Chat stream' => ['openai', 'chat_completions', 'stream', StructuredMode::Auto, []],
    'Chat structured' => ['openai', 'chat_completions', 'structured', StructuredMode::Auto, []],
]);

it('keeps billed signatures and tool content while mapping only the canonical replay', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $signature = str_repeat('opaque-signature', 3000);
    $arguments = ['payload' => str_repeat('argument', 5000)];
    $generation = Prism::text()->using(Provider::Anthropic, 'claude-sonnet-4-6')->withMessages([
        new UserMessage('Question'),
        new AssistantMessage('', [new ToolCall('call-1', 'search', $arguments)], [
            'content_blocks' => [
                ['type' => 'thinking', 'thinking' => 'Retained reasoning', 'signature' => $signature],
                ['type' => 'tool_use', 'id' => 'call-1', 'name' => 'search', 'input' => $arguments],
            ],
            'thinking_blocks' => [['type' => 'thinking', 'thinking' => 'Retained reasoning', 'signature' => $signature]],
            'thinking' => 'Retained reasoning',
            'thinking_signature' => $signature,
        ]),
        new ToolResultMessage([new ToolResult('call-1', 'search', $arguments, ['signature' => 'billed tool result'])]),
    ]);
    $request = $generation->toRequest();
    $original = serialize($request);
    $payload = $generation->provider()->projectPayload($request, 'text');
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    expect(substr_count($body, $signature))->toBe(1)
        ->and(substr_count($body, $arguments['payload']))->toBe(1)
        ->and($body)->toContain('Retained reasoning', 'billed tool result')
        ->and(serialize($request))->toBe($original);
    Http::assertNothingSent();
});

it('returns explicit absence for a provider without a projection', function (): void {
    $request = Prism::text()->using(Provider::OpenAI, 'gpt-4o')->withPrompt('Test')->toRequest();
    expect((new class extends BaseProvider {})->projectPayload($request, 'text'))->toBeNull();
});

it('rejects a mismatched mode before dispatch or mutation', function (string $provider): void {
    Http::preventStrayRequests();
    Http::fake();
    $generation = Prism::text()->using($provider, 'test-model')->withPrompt('Test');
    $request = $generation->toRequest();
    $original = serialize($request);
    expect(fn () => $generation->provider()->projectPayload($request, 'structured'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $generation->provider()->projectPayload($request, 'unknown'))->toThrow(InvalidArgumentException::class);
    expect(serialize($request))->toBe($original);
    Http::assertNothingSent();
})->with(['anthropic', 'openai']);

it('refuses unresolved approvals without evaluating callbacks and accepts resolved history', function (string $provider, string $apiFormat, string $mode, bool $approved): void {
    Http::preventStrayRequests();
    Http::fake();
    $called = 0;
    $approvalChecked = 0;
    $tool = (new Tool)->as('sensitive')->for('Sensitive operation')
        ->using(function () use (&$called): string {
            $called++;

            return 'result';
        })
        ->requiresApproval(function () use (&$approvalChecked): bool {
            $approvalChecked++;

            return true;
        });
    $assistant = new AssistantMessage('', [new ToolCall('call-1', 'sensitive', [])], [], [new ToolApprovalRequest('approval-1', 'call-1')]);
    $decision = new ToolResultMessage([], [new ToolApprovalResponse('approval-1', $approved)]);
    $generation = $mode === 'structured' ? Prism::structured()->withSchema(new StringSchema('value', 'Value')) : Prism::text();
    $generation->using($provider, 'test-model', ['api_format' => $apiFormat])->withTools([$tool])->withMessages([
        new UserMessage('Question'), $assistant, $decision,
    ]);
    $request = $generation->toRequest();
    $before = $request->messages();
    expect(fn () => $generation->provider()->projectPayload($request, $mode))->toThrow(PrismUnprojectableRequestException::class);
    expect($called)->toBe(0)->and($approvalChecked)->toBe(0)->and($request->messages())->toBe($before);

    $request->addMessage(new ToolResultMessage([new ToolResult('call-1', 'sensitive', [], 'already resolved')]));
    expect($generation->provider()->projectPayload($request, $mode))->toBeArray();
    expect($called)->toBe(0)->and($approvalChecked)->toBe(0);
    Http::assertNothingSent();
})->with([
    ['anthropic', 'responses', 'text'],
    ['anthropic', 'responses', 'structured'],
    ['openai', 'responses', 'text'],
    ['openai', 'responses', 'structured'],
    ['openai', 'chat_completions', 'text'],
    ['openai', 'chat_completions', 'stream'],
])->with([true, false]);

it('keeps legacy chat structured projection available because that sender does not resolve approvals', function (): void {
    Http::preventStrayRequests();
    $called = 0;
    $tool = (new Tool)->as('sensitive')->for('Sensitive operation')->requiresApproval()
        ->using(function () use (&$called): string {
            $called++;

            return 'result';
        });
    $generation = Prism::structured()->using('openai', 'gpt-4o', ['api_format' => 'chat_completions'])
        ->withSchema(new StringSchema('value', 'Value'))->withTools([$tool])->withMessages([
            new UserMessage('Question'),
            new AssistantMessage('', [new ToolCall('call-1', 'sensitive', [])], [], [new ToolApprovalRequest('approval-1', 'call-1')]),
            new ToolResultMessage([], [new ToolApprovalResponse('approval-1', true)]),
        ]);
    $request = $generation->toRequest();
    $projection = $generation->provider()->projectPayload($request, 'structured');
    $intercepted = new RuntimeException('Intercepted chat structured');
    $sent = null;
    Http::fake(function (HttpRequest $wire) use (&$sent, $intercepted): never {
        $sent = $wire;
        throw $intercepted;
    });
    expect(fn () => $generation->provider()->structured($request))->toThrow($intercepted);
    expect($called)->toBe(0)
        ->and(json_decode($sent->body(), true, flags: JSON_THROW_ON_ERROR))->toBe($projection);
});

it('projects a remote image reference without fetching its content', function (string $provider): void {
    Http::preventStrayRequests();
    Http::fake();
    $image = Image::fromUrl('https://unreachable.invalid/private-image.png');
    $generation = Prism::text()->using($provider, 'test-model')->withPrompt('Describe', [$image]);
    $request = $generation->toRequest();
    $original = serialize($request);
    $payload = $generation->provider()->projectPayload($request, 'text');
    expect(json_encode($payload))->toContain('unreachable.invalid')
        ->and(serialize($request))->toBe($original);
    Http::assertNothingSent();
})->with(['anthropic', 'openai']);
