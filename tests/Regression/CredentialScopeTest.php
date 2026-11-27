<?php

declare(strict_types=1);

namespace Tests\Regression;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Prism\Prism\Enums\Provider as ProviderEnum;
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

/**
 * A provider name is a positive allow-list, not a list of forbidden
 * characters: ASCII `^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$`. A homoglyph,
 * full-width or padded `openai` is a second, different key that reads as the
 * built-in in a config file or a log; any character test that relies on a
 * language's Unicode tables disagrees with the other languages somewhere. The
 * same rule is spelled in prism-ts registerProvider() and prism-py
 * register_provider(). Collisions stay ASCII case-insensitive.
 */
it('refuses a provider name outside the ASCII allow-list', function (string $name): void {
    $manager = resolve(PrismManager::class);

    expect(fn (): PrismManager => $manager->extend($name, fn (): Provider => new TestProvider))
        ->toThrow(InvalidArgumentException::class, 'not a valid provider name');

    // Refused even when replacement is explicit: replace does not license a name.
    expect(fn (): PrismManager => $manager->extend($name, fn (): Provider => new TestProvider, replace: true))
        ->toThrow(InvalidArgumentException::class, 'not a valid provider name');

    expect($manager->resolve('openai'))->not->toBeInstanceOf(TestProvider::class);
})->with([
    'empty' => [''],
    'leading space' => [' openai'],
    'trailing space' => ['openai '],
    'interior space' => ['open ai'],
    'tab' => ["openai\t"],
    'newline' => ["openai\n"],
    'NUL' => ["openai\u{0000}"],
    'C0 control' => ["openai\u{001F}"],
    'NEL' => ["openai\u{0085}"],
    'no-break space' => ["openai\u{00A0}"],
    'ideographic space' => ["open\u{3000}ai"],
    'zero-width space' => ["openai\u{200B}"],
    'zero-width joiner' => ["open\u{200D}ai"],
    'BOM prefix' => ["\u{FEFF}openai"],
    'right-to-left override (Cf)' => ["openai\u{202E}"],
    'word joiner (Cf, outside G-36)' => ["openai\u{2060}"],
    'soft hyphen (Cf)' => ["open\u{00AD}ai"],
    'invalid UTF-8' => ["openai\xC3"],
    'Cyrillic o homoglyph' => ["\u{043E}penai"],
    'Cyrillic a homoglyph' => ["open\u{0430}i"],
    'full-width' => ["\u{FF4F}\u{FF50}\u{FF45}\u{FF4E}\u{FF41}\u{FF49}"],
    'Latin-1 letter' => ['caf'."\u{00E9}"],
    'leading hyphen' => ['-openai'],
    'leading dot' => ['.openai'],
    'leading underscore' => ['_openai'],
    'colon' => ['acme:edge'],
    'slash' => ['acme/edge'],
    '65 characters' => [str_repeat('a', 65)],
]);

it('still accepts an ordinary custom provider name', function (string $name): void {
    $manager = resolve(PrismManager::class);
    $manager->extend($name, fn (): Provider => new TestProvider);

    expect($manager->resolve($name))->toBeInstanceOf(TestProvider::class);
})->with([
    'hyphenated' => ['credential-scope-plain'],
    'underscore and dot' => ['my_provider.v2'],
    'leading digit' => ['0day'],
    'one character' => ['q'],
    '64 characters' => [str_repeat('a', 64)],
]);

it('accepts every built-in provider name under the allow-list', function (ProviderEnum $provider): void {
    $manager = resolve(PrismManager::class);

    // Replacing is the only way to register over a built-in; the name itself must pass.
    expect($manager->extend($provider->value, fn (): Provider => new TestProvider, replace: true))
        ->toBeInstanceOf(PrismManager::class);
})->with(ProviderEnum::cases());

/**
 * A url that carries whitespace or a control character anywhere — padded,
 * interior, or an invisible codepoint — is refused before any request, whether
 * it arrives per call or from configuration. Trimming it would make the
 * endpoint comparison depend on each HTTP stack's tolerance (PHP's verdict
 * moved with the Guzzle major); refusing it is the same in every language. The
 * error names neither the url nor the key.
 *
 * The forbidden set is spelled by codepoint, identically in prism-ts and
 * prism-py: every Cc control (U+0000..U+001F, U+007F..U+009F), every Unicode
 * White_Space codepoint, and the G-36 zero-widths U+200B..U+200D and U+FEFF.
 */
it('refuses a per-call url carrying whitespace or a control character before any request', function (string $url): void {
    configureCredentialScope('openai', 'https://api.openai.com/v1');
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => ['message' => 'fixture']], 500)]);

    $error = null;

    try {
        Prism::text()->using('openai', 'fixture-model', ['url' => $url])->withPrompt('hi')->asText();
    } catch (Throwable $caught) {
        $error = $caught;
    }

    expect($error)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($error->getMessage())->toContain('url must not contain whitespace or control characters')
        ->and($error->getMessage())->not->toContain('openai.com')
        ->and($error->getMessage())->not->toContain('configured-secret-openai');

    Http::assertNothingSent();
})->with([
    'padded both ends (cs-0016)' => [' https://api.openai.com/v1 '],
    'leading space' => [' https://api.openai.com/v1'],
    'trailing space' => ['https://api.openai.com/v1 '],
    'interior space' => ['https://api.openai.com/v1 x'],
    'tab' => ["https://api.openai.com/v1\t"],
    'newline' => ["https://api.openai.com/v1\n"],
    'carriage return' => ["\rhttps://api.openai.com/v1"],
    'NUL' => ["https://api.openai.com\u{0000}/v1"],
    'DEL' => ["https://api.openai.com/v1\u{007F}"],
    'C1 control' => ["https://api.openai.com/v1\u{009B}"],
    'NEL' => ["https://api.openai.com/v1\u{0085}"],
    'no-break space' => ["\u{00A0}https://api.openai.com/v1"],
    'ideographic space' => ["https://api.openai.com/v1\u{3000}"],
    'zero-width space' => ["https://api.openai.com\u{200B}/v1"],
    'BOM prefix' => ["\u{FEFF}https://api.openai.com/v1"],
]);

it('refuses a configured url carrying whitespace or a control character before any request', function (): void {
    configureCredentialScope('openai', "https://api.openai.com/v1\n");
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => ['message' => 'fixture']], 500)]);

    expect(fn (): mixed => Prism::text()->using('openai', 'fixture-model')->withPrompt('hi')->asText())
        ->toThrow(InvalidArgumentException::class, 'url must not contain whitespace or control characters');

    Http::assertNothingSent();
});

it('refuses a padded url for a custom provider before its creator runs', function (): void {
    config()->set('prism.providers.custom-padded', [
        'url' => 'https://configured.example/v1',
        'api_key' => 'configured-secret-custom',
    ]);

    $called = false;
    resolve(PrismManager::class)->extend('custom-padded', function () use (&$called): Provider {
        $called = true;

        return new TestProvider;
    });

    expect(fn (): Provider => resolve(PrismManager::class)->resolve('custom-padded', ['url' => ' https://configured.example/v1']))
        ->toThrow(InvalidArgumentException::class, 'url must not contain whitespace or control characters');

    expect($called)->toBeFalse();
});
