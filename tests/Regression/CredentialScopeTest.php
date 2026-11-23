<?php

declare(strict_types=1);

namespace Tests\Regression;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;
use Tests\TestDoubles\TestProvider;
use Throwable;

/**
 * A credential from configuration is scoped to the endpoint it was configured
 * for. A per-call provider config that points the provider somewhere else
 * must bring its own key; the configured one is never forwarded.
 *
 * Every assertion enters where an application does — Prism::text() /
 * embeddings() / audio() with ->using(name, model, overrides) — and inspects
 * what actually went over the wire. The key is searched for in the URL, every
 * header and the body, because providers carry it in different places
 * (Authorization, x-api-key, api-key, x-goog-api-key, xi-api-key).
 */

/**
 * Every built-in provider that authenticates with `api_key` against `url`,
 * with the entry point that reaches its HTTP client.
 *
 * @return array<string, array{0: string, 1: Closure(string, array<string, mixed>): mixed}>
 */
function credentialScopeProviders(): array
{
    // Perplexity refuses an unknown model before sending; 'sonar' is a known preset.
    $text = fn (string $name, array $config): mixed => Prism::text()
        ->using($name, $name === 'perplexity' ? 'sonar' : 'fixture-model', $config)
        ->withPrompt('hi')
        ->asText();

    $embeddings = fn (string $name, array $config): mixed => Prism::embeddings()
        ->using($name, 'fixture-model', $config)
        ->fromInput('hi')
        ->asEmbeddings();

    $audio = fn (string $name, array $config): mixed => Prism::audio()
        ->using($name, 'fixture-model', $config)
        ->withInput('hi')
        ->withVoice('fixture')
        ->asAudio();

    return [
        'openai' => ['openai', $text],
        'anthropic' => ['anthropic', $text],
        'azure' => ['azure', $text],
        'deepseek' => ['deepseek', $text],
        'elevenlabs' => ['elevenlabs', $audio],
        'gemini' => ['gemini', $text],
        'groq' => ['groq', $text],
        'mistral' => ['mistral', $text],
        'ollama' => ['ollama', $text],
        'openrouter' => ['openrouter', $text],
        'perplexity' => ['perplexity', $text],
        'qwen' => ['qwen', $text],
        'replicate' => ['replicate', $text],
        'requesty' => ['requesty', $text],
        'voyageai' => ['voyageai', $embeddings],
        'xai' => ['xai', $text],
        'z' => ['z', $text],
    ];
}

function configureCredentialScope(string $name, string $url): void
{
    config()->set("prism.providers.{$name}.url", $url);
    config()->set("prism.providers.{$name}.api_key", "configured-secret-{$name}");
}

/**
 * Run the call, tolerating the failure a faked 500 produces, and return the
 * recorded requests flattened to one searchable string each.
 *
 * @param  Closure(string, array<string, mixed>): mixed  $call
 * @param  array<string, mixed>  $overrides
 * @return array<int, string>
 */
function sentRequests(Closure $call, string $name, array $overrides): array
{
    Http::fake(['*' => Http::response(['error' => ['message' => 'fixture']], 500)]);

    try {
        $call($name, $overrides);
    } catch (Throwable) {
        // The response is a fixture failure; only the outgoing request matters.
    }

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

function configuredUrlFor(string $name): string
{
    return $name === 'azure'
        ? 'https://configured.openai.azure.com/openai/deployments/fixture'
        : (string) config("prism.providers.{$name}.url");
}

it('does not send the configured key to a per-call URL that brings no key', function (string $name, Closure $call): void {
    configureCredentialScope($name, configuredUrlFor($name));

    $requests = sentRequests($call, $name, ['url' => 'https://other-host.example/v1']);

    expect($requests)->not->toBeEmpty();

    foreach ($requests as $request) {
        expect($request)->toContain('other-host.example')
            ->and($request)->not->toContain("configured-secret-{$name}");
    }
})->with(credentialScopeProviders());

it('sends the key supplied alongside a per-call URL', function (string $name, Closure $call): void {
    configureCredentialScope($name, configuredUrlFor($name));

    $requests = sentRequests($call, $name, [
        'url' => 'https://other-host.example/v1',
        'api_key' => "explicit-key-{$name}",
    ]);

    expect($requests)->not->toBeEmpty();

    foreach ($requests as $request) {
        expect($request)->toContain("explicit-key-{$name}")
            ->and($request)->not->toContain("configured-secret-{$name}");
    }
})->with(credentialScopeProviders());

it('keeps the configured key when the per-call URL is the configured one', function (string $name, Closure $call): void {
    $url = configuredUrlFor($name);
    configureCredentialScope($name, $url);

    $requests = sentRequests($call, $name, ['url' => $url]);

    expect($requests)->not->toBeEmpty();

    foreach ($requests as $request) {
        expect($request)->toContain("configured-secret-{$name}");
    }
})->with(credentialScopeProviders());

it('keeps the configured key when the per-call URL differs only trivially', function (string $name, Closure $call): void {
    $url = configuredUrlFor($name);
    configureCredentialScope($name, rtrim($url, '/'));

    // Trailing slash added, and scheme + host upper-cased.
    $variant = preg_replace_callback(
        '#^([a-z]+://[^/]+)#',
        fn (array $m): string => strtoupper($m[1]),
        rtrim($url, '/').'/',
    );

    $requests = sentRequests($call, $name, ['url' => $variant]);

    expect($requests)->not->toBeEmpty();

    foreach ($requests as $request) {
        expect($request)->toContain("configured-secret-{$name}");
    }
})->with(credentialScopeProviders());

it('keeps the configured key for an override that does not touch the URL', function (string $name, Closure $call): void {
    configureCredentialScope($name, configuredUrlFor($name));

    $requests = sentRequests($call, $name, ['unrelated_option' => 'x']);

    expect($requests)->not->toBeEmpty();

    foreach ($requests as $request) {
        expect($request)->toContain("configured-secret-{$name}");
    }
})->with(credentialScopeProviders());

it('treats a different port on the same host as a different endpoint', function (): void {
    configureCredentialScope('openai', 'https://api.openai.com/v1');

    $requests = sentRequests(
        fn (string $name, array $config): mixed => Prism::text()->using($name, 'm', $config)->withPrompt('hi')->asText(),
        'openai',
        ['url' => 'https://api.openai.com:8443/v1'],
    );

    expect($requests)->not->toBeEmpty();

    foreach ($requests as $request) {
        expect($request)->not->toContain('configured-secret-openai');
    }
});

it('drops the configured key when no URL was configured and the call supplies one', function (): void {
    config()->set('prism.providers.openai.url', '');
    config()->set('prism.providers.openai.api_key', 'configured-secret-openai');

    $requests = sentRequests(
        fn (string $name, array $config): mixed => Prism::text()->using($name, 'm', $config)->withPrompt('hi')->asText(),
        'openai',
        ['url' => 'https://other-host.example/v1'],
    );

    expect($requests)->not->toBeEmpty();

    foreach ($requests as $request) {
        expect($request)->not->toContain('configured-secret-openai');
    }
});

it('scopes the configured key for a custom provider the same way', function (): void {
    config()->set('prism.providers.custom-scope', [
        'url' => 'https://configured.example/v1',
        'api_key' => 'configured-secret-custom',
    ]);

    $received = [];
    resolve(PrismManager::class)->extend('custom-scope', function ($app, array $config) use (&$received): Provider {
        $received[] = $config;

        return new TestProvider;
    });

    resolve(PrismManager::class)->resolve('custom-scope', ['url' => 'https://other-host.example/v1']);
    resolve(PrismManager::class)->resolve('custom-scope', ['url' => 'https://configured.example/v1/']);

    expect($received[0])->not->toHaveKey('api_key')
        ->and($received[0]['url'])->toBe('https://other-host.example/v1')
        ->and($received[1]['api_key'])->toBe('configured-secret-custom');
});

it('refuses a Vertex region that would change the host the token is sent to', function (string $region): void {
    config()->set('prism.providers.vertex.project_id', 'fixture-project');
    config()->set('prism.providers.vertex.access_token', 'configured-secret-vertex');

    Http::fake(['*' => Http::response([], 500)]);

    expect(fn (): mixed => Prism::text()
        ->using('vertex', 'gemini-fixture', ['region' => $region])
        ->withPrompt('hi')
        ->asText()
    )->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'path break' => ['other-host.example/x?'],
    'fragment break' => ['other-host.example#'],
    'userinfo break' => ['x@other-host.example/'],
    'port' => ['other-host.example:443/'],
]);

it('still accepts ordinary Vertex regions', function (string $region): void {
    config()->set('prism.providers.vertex.project_id', 'fixture-project');
    config()->set('prism.providers.vertex.access_token', 'configured-secret-vertex');

    Http::fake(['*' => Http::response([], 500)]);

    try {
        Prism::text()->using('vertex', 'gemini-fixture', ['region' => $region])->withPrompt('hi')->asText();
    } catch (Throwable) {
    }

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis.com/')
        && $request->hasHeader('Authorization', 'Bearer configured-secret-vertex'));
})->with(['us-central1', 'europe-west4', 'global', 'eu', 'us']);

it('refuses to register a provider under a built-in name', function (string $name): void {
    expect(fn (): PrismManager => resolve(PrismManager::class)->extend($name, fn (): Provider => new TestProvider))
        ->toThrow(InvalidArgumentException::class);

    // The built-in is still what resolves.
    expect(resolve(PrismManager::class)->resolve(strtolower($name)))->not->toBeInstanceOf(TestProvider::class);
})->with(['openai', 'OpenAI', 'anthropic', 'gemini', 'vertex']);

it('refuses to silently replace an already-registered custom provider', function (): void {
    $manager = resolve(PrismManager::class);
    $manager->extend('custom-once', fn (): Provider => new TestProvider);

    expect(fn (): PrismManager => $manager->extend('custom-once', fn (): Provider => new TestProvider))
        ->toThrow(InvalidArgumentException::class);
});

it('replaces a provider when replacement is explicit', function (): void {
    $manager = resolve(PrismManager::class);

    $manager->extend('openai', fn (): Provider => new TestProvider, replace: true);

    expect($manager->resolve('openai'))->toBeInstanceOf(TestProvider::class);
});
