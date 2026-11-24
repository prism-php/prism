<?php

declare(strict_types=1);

namespace Tests\Regression;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Throwable;

/**
 * A named OpenAI-compatible provider is declared in configuration:
 *
 *     'my-vllm' => ['driver' => 'openai-compatible', 'url' => ..., 'api_key' => ...]
 *
 * It authenticates with ITS OWN entry and nothing else: never the `openai`
 * provider's key, organization or project, and never an OPENAI_* environment
 * variable. Every assertion enters where an application does — Prism::text()
 * / structured() with ->using(name, model) — and inspects what went over the
 * wire. Keys are fixtures; the network is closed before the first request.
 */
beforeEach(function (): void {
    Http::preventStrayRequests();

    // The `openai` provider is fully configured with values that must never
    // reach a compatible endpoint, and so is the process environment.
    config()->set('prism.providers.openai.api_key', 'sk-test-openai-secret');
    config()->set('prism.providers.openai.organization', 'org-test-openai');
    config()->set('prism.providers.openai.project', 'proj-test-openai');
    putenv('OPENAI_API_KEY=sk-test-env-secret');
    putenv('OPENAI_ORGANIZATION=org-test-env');
    putenv('OPENAI_PROJECT=proj-test-env');
});

afterEach(function (): void {
    putenv('OPENAI_API_KEY');
    putenv('OPENAI_ORGANIZATION');
    putenv('OPENAI_PROJECT');
});

/**
 * @param  array<string, mixed>  $entry
 */
function configureCompatible(string $name, array $entry): void
{
    config()->set('prism.providers', array_merge(
        (array) config('prism.providers'),
        [$name => array_merge(['driver' => 'openai-compatible'], $entry)],
    ));
}

/**
 * @return array{choices: list<array<string, mixed>>, usage: array<string, int>, id: string, model: string}
 */
function chatCompletion(string $content = 'hello'): array
{
    return [
        'id' => 'chatcmpl-fixture',
        'model' => 'fixture-model',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $content],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2],
    ];
}

/**
 * Every recorded request flattened to one searchable string: URL, every
 * header, body.
 *
 * @return list<string>
 */
function compatibleWire(): array
{
    return Http::recorded()
        ->map(fn (array $pair): string => (function (Request $request): string {
            $headers = collect($request->headers())
                ->map(fn (array $values, string $key): string => $key.': '.implode(',', $values))
                ->implode("\n");

            return $request->url()."\n".$headers."\n".$request->body();
        })($pair[0]))
        ->values()
        ->all();
}

function expectNoForeignCredential(string $wire): void
{
    foreach (['sk-test-openai-secret', 'sk-test-env-secret', 'org-test-openai', 'proj-test-openai', 'org-test-env', 'proj-test-env'] as $foreign) {
        expect($wire)->not->toContain($foreign);
    }
}

function refusal(callable $call): Throwable
{
    try {
        $call();
    } catch (Throwable $error) {
        return $error;
    }

    throw new \RuntimeException('Expected the call to be refused.');
}

it('sends a compatible provider its own key, to its own url, in the chat completions format', function (): void {
    configureCompatible('my-vllm', [
        'url' => 'https://vllm.internal.example/v1',
        'api_key' => 'sk-test-vllm',
        'api_format' => 'chat_completions',
    ]);
    Http::fake(['https://vllm.internal.example/v1/chat/completions' => Http::response(chatCompletion('hi there'))]);

    $response = Prism::text()->using('my-vllm', 'fixture-model')->withPrompt('hi')->asText();

    expect($response->text)->toBe('hi there');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://vllm.internal.example/v1/chat/completions'
        && $request->header('Authorization') === ['Bearer sk-test-vllm']);

    foreach (compatibleWire() as $wire) {
        expectNoForeignCredential($wire);
    }
});

it('defaults a compatible provider to the chat completions format', function (): void {
    configureCompatible('my-vllm', ['url' => 'https://vllm.internal.example/v1', 'api_key' => 'sk-test-vllm']);
    Http::fake(['https://vllm.internal.example/v1/chat/completions' => Http::response(chatCompletion())]);

    Prism::text()->using('my-vllm', 'fixture-model')->withPrompt('hi')->asText();

    Http::assertSentCount(1);
});

it('sends no authorization header at all when the entry has no key, whatever openai and the environment hold', function (?string $key): void {
    configureCompatible('my-vllm', array_filter([
        'url' => 'https://vllm.internal.example/v1',
        'api_key' => $key,
    ], fn (mixed $value): bool => $value !== 'absent'));
    Http::fake(['https://vllm.internal.example/v1/chat/completions' => Http::response(chatCompletion())]);

    Prism::text()->using('my-vllm', 'fixture-model')->withPrompt('hi')->asText();

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('OpenAI-Organization')
        && ! $request->hasHeader('OpenAI-Project'));

    foreach (compatibleWire() as $wire) {
        expectNoForeignCredential($wire);
    }
})->with([
    'absent' => ['absent'],
    'null' => [null],
    'empty' => [''],
]);

it('sends only the entry organization and project', function (): void {
    configureCompatible('my-vllm', [
        'url' => 'https://vllm.internal.example/v1',
        'organization' => 'org-test-vllm',
        'project' => 'proj-test-vllm',
    ]);
    Http::fake(['https://vllm.internal.example/v1/chat/completions' => Http::response(chatCompletion())]);

    Prism::text()->using('my-vllm', 'fixture-model')->withPrompt('hi')->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('OpenAI-Organization') === ['org-test-vllm']
        && $request->header('OpenAI-Project') === ['proj-test-vllm']);
});

it('accepts a private or loopback url, and sends it only the entry key', function (string $url): void {
    configureCompatible('my-vllm', ['url' => $url, 'api_key' => 'sk-test-vllm']);
    Http::fake([rtrim($url, '/').'/chat/completions' => Http::response(chatCompletion())]);

    Prism::text()->using('my-vllm', 'fixture-model')->withPrompt('hi')->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer sk-test-vllm']);
    foreach (compatibleWire() as $wire) {
        expectNoForeignCredential($wire);
    }
})->with([
    'private' => ['http://10.0.0.5:8000/v1'],
    'loopback' => ['http://127.0.0.1:1234/v1'],
    'localhost' => ['http://localhost:11434/v1'],
]);

it('refuses a url that is missing, not http(s), relative or padded, without naming it', function (mixed $url): void {
    configureCompatible('my-vllm', array_filter(['url' => $url, 'api_key' => 'sk-test-vllm'], fn (mixed $value): bool => $value !== 'absent'));
    Http::fake();

    $error = refusal(fn () => Prism::text()->using('my-vllm', 'fixture-model')->withPrompt('hi')->asText());

    expect($error)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($error->getMessage())->toContain('not a valid provider url')
        ->and($error->getMessage())->not->toContain('sk-test-vllm');

    if (is_string($url) && $url !== '') {
        expect($error->getMessage())->not->toContain($url);
    }

    Http::assertNothingSent();
})->with([
    'absent' => ['absent'],
    'null' => [null],
    'empty' => [''],
    'file' => ['file:///etc/passwd'],
    'ftp' => ['ftp://files.example/v1'],
    'relative' => ['/v1'],
    'no host' => ['https:///v1'],
    'padded' => [' https://vllm.internal.example/v1'],
    'not a string' => [['https://vllm.internal.example/v1']],
]);

it('drops the entry key when a per-call url points somewhere else', function (): void {
    configureCompatible('my-vllm', ['url' => 'https://vllm.internal.example/v1', 'api_key' => 'sk-test-vllm']);
    Http::fake(['https://elsewhere.example/v1/chat/completions' => Http::response(chatCompletion())]);

    Prism::text()->using('my-vllm', 'fixture-model', ['url' => 'https://elsewhere.example/v1'])->withPrompt('hi')->asText();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://elsewhere.example/v1/')
        && ! $request->hasHeader('Authorization'));
    foreach (compatibleWire() as $wire) {
        expect($wire)->not->toContain('sk-test-vllm');
        expectNoForeignCredential($wire);
    }
});

it('uses a key passed with the per-call url', function (): void {
    configureCompatible('my-vllm', ['url' => 'https://vllm.internal.example/v1', 'api_key' => 'sk-test-vllm']);
    Http::fake(['https://elsewhere.example/v1/chat/completions' => Http::response(chatCompletion())]);

    Prism::text()
        ->using('my-vllm', 'fixture-model', ['url' => 'https://elsewhere.example/v1', 'api_key' => 'sk-test-explicit'])
        ->withPrompt('hi')
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer sk-test-explicit']);
});

it('refuses a per-call url that is not http(s)', function (): void {
    configureCompatible('my-vllm', ['url' => 'https://vllm.internal.example/v1', 'api_key' => 'sk-test-vllm']);
    Http::fake();

    $error = refusal(fn () => Prism::text()->using('my-vllm', 'fixture-model', ['url' => 'file:///etc/passwd'])->withPrompt('hi')->asText());

    expect($error->getMessage())->toContain('not a valid provider url');
    Http::assertNothingSent();
});

it('refuses a compatible entry under a built-in name, in any case', function (string $name, string $call): void {
    configureCompatible($name, ['url' => 'https://attacker.example/v1']);
    Http::fake();

    $error = refusal(fn () => Prism::text()->using($call, 'fixture-model')->withPrompt('hi')->asText());

    expect($error)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($error->getMessage())->toContain('already registered');
    Http::assertNothingSent();
})->with([
    'openai' => ['openai', 'openai'],
    'OpenAI' => ['OpenAI', 'openai'],
    'ANTHROPIC' => ['ANTHROPIC', 'anthropic'],
    'mistral' => ['mistral', 'mistral'],
]);

it('refuses a compatible entry whose name is not a valid provider name', function (string $name): void {
    configureCompatible($name, ['url' => 'https://vllm.internal.example/v1', 'api_key' => 'sk-test-vllm']);
    Http::fake();

    $error = refusal(fn () => Prism::text()->using($name, 'fixture-model')->withPrompt('hi')->asText());

    expect($error)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($error->getMessage())->toContain('not a valid provider name');
    Http::assertNothingSent();
})->with([
    'leading space' => [' my-vllm'],
    'trailing space' => ['my-vllm '],
    'zero width' => ["my\u{200B}-vllm"],
    'cyrillic o' => ["\u{043E}penai"],
]);

it('resolves a mixed-case name that collides with nothing', function (): void {
    configureCompatible('My-VLLM', ['url' => 'https://vllm.internal.example/v1', 'api_key' => 'sk-test-vllm']);
    Http::fake(['https://vllm.internal.example/v1/chat/completions' => Http::response(chatCompletion())]);

    Prism::text()->using('My-VLLM', 'fixture-model')->withPrompt('hi')->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer sk-test-vllm']);
});

it('refuses two compatible entries that differ only in case', function (): void {
    configureCompatible('my-vllm', ['url' => 'https://a.example/v1']);
    configureCompatible('MY-VLLM', ['url' => 'https://b.example/v1']);
    Http::fake();

    $error = refusal(fn () => Prism::text()->using('my-vllm', 'fixture-model')->withPrompt('hi')->asText());

    expect($error->getMessage())->toContain('already registered');
    Http::assertNothingSent();
});

it('refuses an api format it does not know', function (): void {
    configureCompatible('my-vllm', ['url' => 'https://vllm.internal.example/v1', 'api_format' => 'completions']);
    Http::fake();

    $error = refusal(fn () => Prism::text()->using('my-vllm', 'fixture-model')->withPrompt('hi')->asText());

    expect($error)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($error->getMessage())->toContain('not a valid api format');
    Http::assertNothingSent();
});

it('speaks the responses format when the entry asks for it', function (): void {
    configureCompatible('my-gateway', ['url' => 'https://gateway.example/v1', 'api_key' => 'sk-test-vllm', 'api_format' => 'responses']);
    Http::fake(['*' => Http::response(['error' => ['message' => 'fixture']], 500)]);

    try {
        Prism::text()->using('my-gateway', 'fixture-model')->withPrompt('hi')->asText();
    } catch (Throwable) {
        // Only the outgoing request matters.
    }

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gateway.example/v1/responses');
});

it('refuses an extend() over a configured compatible name unless replaced', function (): void {
    configureCompatible('my-vllm', ['url' => 'https://vllm.internal.example/v1', 'api_key' => 'sk-test-vllm']);
    $stub = fn (): Provider => new class extends Provider {};

    $error = refusal(fn () => app(PrismManager::class)->extend('My-Vllm', $stub));

    expect($error->getMessage())->toContain('already registered');

    app(PrismManager::class)->extend('my-vllm', $stub, replace: true);

    expect(app(PrismManager::class)->resolve('my-vllm'))->not->toBeInstanceOf(\Prism\Prism\Providers\OpenAI\OpenAI::class);
});

it('refuses a compatible entry that a custom creator registered earlier would shadow', function (): void {
    $received = [];
    app(PrismManager::class)->extend('my-vllm', function ($app, array $config) use (&$received): Provider {
        $received = $config;

        return new class extends Provider {};
    });
    configureCompatible('my-vllm', ['url' => 'https://vllm.internal.example/v1', 'api_key' => 'sk-test-vllm']);

    $error = refusal(fn () => app(PrismManager::class)->resolve('my-vllm'));

    expect($error->getMessage())->toContain('already registered')
        ->and($received)->toBe([]);
});

it('tags telemetry with the entry name, not openai', function (): void {
    config()->set('prism.telemetry.enabled', true);
    Event::fake([GenerationStarted::class]);
    configureCompatible('my-vllm', ['url' => 'https://vllm.internal.example/v1', 'api_key' => 'sk-test-vllm']);
    Http::fake(['https://vllm.internal.example/v1/chat/completions' => Http::response(chatCompletion())]);

    Prism::text()->using('my-vllm', 'fixture-model')->withPrompt('hi')->asText();

    Event::assertDispatched(GenerationStarted::class, fn (GenerationStarted $e): bool => $e->context->provider === 'my-vllm');
});

it('never echoes the key in an error, whether the endpoint fails or cannot be reached', function (string $failure): void {
    configureCompatible('my-vllm', ['url' => 'https://vllm.internal.example/v1', 'api_key' => 'sk-test-vllm']);
    Http::fake(['*' => $failure === 'unreachable'
        ? fn () => throw new ConnectionException('cURL error 7: Failed to connect')
        : Http::response(['error' => ['message' => 'bad gateway']], 502)]);

    $error = refusal(fn () => Prism::text()->using('my-vllm', 'fixture-model')->withPrompt('hi')->asText());

    for ($e = $error; $e instanceof Throwable; $e = $e->getPrevious()) {
        expect($e->getMessage())->not->toContain('sk-test-vllm');
    }
})->with(['unreachable', 'server error']);

it('serves structured output through a compatible provider', function (): void {
    configureCompatible('my-vllm', ['url' => 'https://vllm.internal.example/v1', 'api_key' => 'sk-test-vllm']);
    Http::fake(['https://vllm.internal.example/v1/chat/completions' => Http::response(chatCompletion('{"answer":"yes"}'))]);

    $response = Prism::structured()
        ->using('my-vllm', 'fixture-model')
        ->withSchema(new ObjectSchema('reply', 'a reply', [new StringSchema('answer', 'the answer')], ['answer']))
        ->withPrompt('hi')
        ->asStructured();

    expect($response->structured)->toBe(['answer' => 'yes']);
    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer sk-test-vllm']);
});

it('streams through a compatible provider', function (): void {
    configureCompatible('my-vllm', ['url' => 'https://vllm.internal.example/v1']);
    $chunk = json_encode(['id' => 'c', 'model' => 'fixture-model', 'choices' => [['index' => 0, 'delta' => ['content' => 'hey'], 'finish_reason' => null]]]);
    $stop = json_encode(['id' => 'c', 'model' => 'fixture-model', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]);
    Http::fake(['https://vllm.internal.example/v1/chat/completions' => Http::response(
        "data: {$chunk}\n\ndata: {$stop}\n\ndata: [DONE]\n\n",
        200,
        ['Content-Type' => 'text/event-stream'],
    )]);

    $text = '';
    foreach (Prism::text()->using('my-vllm', 'fixture-model')->withPrompt('hi')->asStream() as $event) {
        if ($event instanceof \Prism\Prism\Streaming\Events\TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($text)->toBe('hey');
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

it('leaves the built-in openai provider and its key untouched', function (): void {
    configureCompatible('my-vllm', ['url' => 'https://vllm.internal.example/v1', 'api_key' => 'sk-test-vllm']);
    Http::fake(['*' => Http::response(['error' => ['message' => 'fixture']], 500)]);

    try {
        Prism::text()->using('openai', 'fixture-model')->withPrompt('hi')->asText();
    } catch (Throwable) {
        // Only the outgoing request matters.
    }

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.openai.com/v1/')
        && $request->header('Authorization') === ['Bearer sk-test-openai-secret']);
});
