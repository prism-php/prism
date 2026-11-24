<?php

declare(strict_types=1);

namespace Tests\Regression;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismUrlRefused;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Tests\TestDoubles\TestProvider;
use Throwable;

/**
 * A provider address in CONFIGURATION may point anywhere: operators run local
 * model servers. A per-call url is chosen by whoever builds the call, and may
 * not point at a loopback, private, link-local or metadata address unless the
 * provider's configuration opts in (Wish, 2026-11-24: "users shouldn't be
 * choosing the address for a single call").
 *
 * Every assertion enters through Prism::text()->using(), with stray requests
 * prevented, and checks that NOTHING was sent and that the refusal does not
 * echo the address.
 */
beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => ['message' => 'fixture']], 500)]);
});

/**
 * Run a text call and return the refusal, or null when the call was not
 * refused by the guard (any other failure, such as the faked 500, is not a
 * refusal).
 *
 * @param  array<string, mixed>  $overrides
 */
function perCallRefusal(string $provider, array $overrides): ?PrismUrlRefused
{
    try {
        Prism::text()->using($provider, 'fixture-model', $overrides)->withPrompt('hi')->asText();
    } catch (PrismUrlRefused $refused) {
        return $refused;
    } catch (Throwable) {
        return null;
    }

    return null;
}

/** @return array<string, array{0: string, 1: string}> */
function refusedPerCallUrls(): array
{
    return [
        'loopback literal' => ['http://127.0.0.1:8000/v1', '127.0.0.1'],
        'mixed-case localhost' => ['HTTP://LocalHost:8000/v1', 'localhost'],
        'localhost with trailing dot' => ['http://localhost./v1', 'localhost'],
        'decimal loopback' => ['http://2130706433/v1', '2130706433'],
        'octal loopback' => ['http://0177.0.0.1/v1', '0177'],
        'hex loopback' => ['http://0x7f000001/v1', '0x7f000001'],
        'two-part hex loopback' => ['http://0x7f.1/v1', '0x7f.1'],
        'short loopback' => ['http://127.1/v1', '127.1'],
        'ipv6 loopback' => ['http://[::1]/v1', '::1'],
        'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/v1', 'ffff'],
        'unique-local ipv6' => ['http://[fd00::1]/v1', 'fd00'],
        'link-local ipv6' => ['http://[fe80::1]/v1', 'fe80'],
        'metadata ip' => ['http://169.254.169.254/latest', '169.254'],
        'metadata name' => ['http://metadata.google.internal/computeMetadata/v1', 'metadata.google'],
        'metadata name, upper case, trailing dot' => ['http://METADATA.GOOGLE.INTERNAL./x', 'GOOGLE.INTERNAL'],
        'unspecified' => ['http://0.0.0.0/v1', '0.0.0.0'],
        'rfc1918 10/8' => ['http://10.0.0.5/v1', '10.0.0.5'],
        'rfc1918 172.16/12' => ['http://172.16.0.1/v1', '172.16'],
        'rfc1918 192.168/16' => ['http://192.168.1.10/v1', '192.168'],
        'alibaba metadata (cgnat)' => ['http://100.100.100.200/x', '100.100'],
        'userinfo trick' => ['http://evil@127.0.0.1/v1', '127.0.0.1'],
        'userinfo with password' => ['http://user:pass@10.0.0.5/v1', '10.0.0.5'],
        'nat64 metadata' => ['http://[64:ff9b::a9fe:a9fe]/', 'a9fe'],
    ];
}

it('refuses a per-call url that points at a private address, before any request', function (string $url, string $fragment): void {
    $refused = perCallRefusal('openai', ['url' => $url]);

    expect($refused)->toBeInstanceOf(PrismUrlRefused::class)
        ->and($refused?->code())->toBe('private_address_refused')
        ->and(strtolower((string) $refused?->getMessage()))->not->toContain(strtolower($fragment));

    Http::assertNothingSent();
})->with(refusedPerCallUrls());

it('refuses a per-call url that is not http or https', function (): void {
    $refused = perCallRefusal('openai', ['url' => 'ftp://example.com/v1']);

    expect($refused?->code())->toBe('scheme_not_allowed')
        ->and((string) $refused?->getMessage())->not->toContain('example.com');

    Http::assertNothingSent();
});

it('sends to a public per-call url', function (): void {
    expect(perCallRefusal('openai', ['url' => 'https://gateway.example/v1']))->toBeNull();

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://gateway.example/v1'));
});

it('leaves a configured private url alone', function (): void {
    config()->set('prism.providers.openai.url', 'http://127.0.0.1:1234/v1');

    expect(perCallRefusal('openai', []))->toBeNull();

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'http://127.0.0.1:1234/v1'));
});

it('accepts a per-call url that names the configured private endpoint', function (): void {
    config()->set('prism.providers.openai.url', 'http://127.0.0.1:1234/v1');

    expect(perCallRefusal('openai', ['url' => 'http://127.0.0.1:1234/v1/']))->toBeNull();

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'http://127.0.0.1:1234/v1'));
});

it('refuses a different private per-call url even when the configured one is private', function (): void {
    config()->set('prism.providers.openai.url', 'http://127.0.0.1:1234/v1');

    expect(perCallRefusal('openai', ['url' => 'http://127.0.0.1:9/v1'])?->code())->toBe('private_address_refused');

    Http::assertNothingSent();
});

it('allows a private per-call url when the provider config opts in', function (): void {
    config()->set('prism.providers.openai.allow_private_per_call_url', true);

    expect(perCallRefusal('openai', ['url' => 'http://127.0.0.1:9/v1']))->toBeNull();

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'http://127.0.0.1:9/v1'));
});

it('reads the opt-out from the environment through the shipped config file', function (): void {
    $config = (function (): array {
        putenv('OPENAI_ALLOW_PRIVATE_PER_CALL_URL=true');
        $_ENV['OPENAI_ALLOW_PRIVATE_PER_CALL_URL'] = 'true';
        $_SERVER['OPENAI_ALLOW_PRIVATE_PER_CALL_URL'] = 'true';

        try {
            return require __DIR__.'/../../config/prism.php';
        } finally {
            putenv('OPENAI_ALLOW_PRIVATE_PER_CALL_URL');
            unset($_ENV['OPENAI_ALLOW_PRIVATE_PER_CALL_URL'], $_SERVER['OPENAI_ALLOW_PRIVATE_PER_CALL_URL']);
        }
    })();

    expect($config['providers']['openai']['allow_private_per_call_url'])->toBeTrue();

    $default = require __DIR__.'/../../config/prism.php';

    foreach ($default['providers'] as $name => $entry) {
        expect($entry)->toHaveKey('allow_private_per_call_url', false, "provider [{$name}] must ship the opt-out, off");
    }
});

it('only a literal true opts in', function (mixed $value): void {
    config()->set('prism.providers.openai.allow_private_per_call_url', $value);

    expect(perCallRefusal('openai', ['url' => 'http://127.0.0.1:9/v1'])?->code())->toBe('private_address_refused');

    Http::assertNothingSent();
})->with([
    'string true' => ['true'],
    'one' => [1],
    'string one' => ['1'],
    'array' => [[true]],
]);

it('does not let the call itself opt in', function (): void {
    $refused = perCallRefusal('openai', [
        'url' => 'http://127.0.0.1:9/v1',
        'allow_private_per_call_url' => true,
    ]);

    expect($refused?->code())->toBe('private_address_refused');

    Http::assertNothingSent();
});

it('guards an openai-compatible entry the same way', function (): void {
    config()->set('prism.providers.my-vllm', [
        'driver' => 'openai-compatible',
        'url' => 'http://127.0.0.1:8000/v1',
        'api_key' => null,
    ]);

    // Configured private url: works.
    expect(perCallRefusal('my-vllm', []))->toBeNull();
    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'http://127.0.0.1:8000/v1'));

    // Per-call private url: refused, nothing more sent.
    $before = count(Http::recorded());
    expect(perCallRefusal('my-vllm', ['url' => 'http://10.0.0.5/v1'])?->code())->toBe('private_address_refused');
    expect(count(Http::recorded()))->toBe($before);
});

it('lets an openai-compatible entry opt in', function (): void {
    config()->set('prism.providers.my-vllm', [
        'driver' => 'openai-compatible',
        'url' => 'https://gateway.example/v1',
        'allow_private_per_call_url' => true,
    ]);

    expect(perCallRefusal('my-vllm', ['url' => 'http://10.0.0.5/v1']))->toBeNull();

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'http://10.0.0.5/v1'));
});

it('guards a custom provider before its creator runs', function (): void {
    $built = false;

    resolve(PrismManager::class)->extend('custom-guarded', function () use (&$built): TestProvider {
        $built = true;

        return new TestProvider;
    });

    expect(perCallRefusal('custom-guarded', ['url' => 'http://169.254.169.254/'])?->code())->toBe('private_address_refused')
        ->and($built)->toBeFalse();
});
