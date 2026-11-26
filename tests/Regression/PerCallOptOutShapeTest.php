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
 * `allow_private_per_call_url` lets a per-call url name a private ADDRESS. It
 * does not let it name another scheme, or a host the guard cannot read the
 * way the HTTP client will: those rules hold with the opt-out on.
 *
 * Every assertion enters through Prism::text()->using(), with stray requests
 * prevented, and checks that nothing was sent and, for a custom provider, that
 * its creator never ran (a creator brings its own client, and gets the url).
 */
beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => ['message' => 'fixture']], 500)]);
});

function optOutRefusal(string $provider, string $url): ?PrismUrlRefused
{
    try {
        Prism::text()->using($provider, 'fixture-model', ['url' => $url])->withPrompt('hi')->asText();
    } catch (PrismUrlRefused $refused) {
        return $refused;
    } catch (Throwable) {
        return null;
    }

    return null;
}

/** @return array<string, array{0: string, 1: string}> */
function optOutShapeUrls(): array
{
    return [
        'file scheme' => ['file:///etc/passwd', 'scheme_not_allowed'],
        'file scheme, upper case' => ['FILE:///etc/passwd', 'scheme_not_allowed'],
        'data scheme' => ['data:text/plain,hello', 'scheme_not_allowed'],
        'ftp scheme' => ['ftp://10.0.0.5/v1', 'scheme_not_allowed'],
        'wrapped url with userinfo' => ['<URL:http://user:pass@10.0.0.5/v1>', 'scheme_not_allowed'],
        'URL: prefix' => ['URL:http://10.0.0.5/v1', 'scheme_not_allowed'],
        'backslash authority' => ['http:\\\\10.0.0.5\\v1', 'scheme_not_allowed'],
        'backslash before the at sign' => ['http://10.0.0.5\\@public.example/v1', 'private_address_refused'],
        'percent-escaped address' => ['http://%31%30.0.0.5/v1', 'private_address_refused'],
        'percent-escaped name' => ['http://loc%61lhost/v1', 'private_address_refused'],
        'non-ascii host' => ['http://ex'."\u{00E4}".'mple.test/v1', 'non_ascii_host_use_punycode'],
        'zone id' => ['http://[fe80::1%25eth0]/v1', 'private_address_refused'],
    ];
}

it('keeps the scheme and host rules with the opt-out on, for a built-in provider', function (string $url, string $code): void {
    config()->set('prism.providers.openai.allow_private_per_call_url', true);

    expect(optOutRefusal('openai', $url)?->code())->toBe($code);

    Http::assertNothingSent();
})->with(optOutShapeUrls());

it('keeps the scheme and host rules with the opt-out on, for an openai-compatible entry', function (string $url, string $code): void {
    config()->set('prism.providers.my-vllm', [
        'driver' => 'openai-compatible',
        'url' => 'https://gateway.example/v1',
        'allow_private_per_call_url' => true,
    ]);

    expect(optOutRefusal('my-vllm', $url)?->code())->toBe($code);

    Http::assertNothingSent();
})->with(optOutShapeUrls());

it('keeps the scheme and host rules with the opt-out on, before a custom creator runs', function (string $url, string $code): void {
    config()->set('prism.providers.custom-opted', ['url' => 'https://gateway.example/v1', 'allow_private_per_call_url' => true]);
    $received = [];

    resolve(PrismManager::class)->extend('custom-opted', function ($app, array $config) use (&$received): TestProvider {
        $received[] = $config['url'] ?? null;

        return new TestProvider;
    });

    expect(optOutRefusal('custom-opted', $url)?->code())->toBe($code)
        ->and($received)->toBe([]);
})->with(optOutShapeUrls());

it('still lets the opt-out name a private address', function (string $url): void {
    config()->set('prism.providers.openai.allow_private_per_call_url', true);

    expect(optOutRefusal('openai', $url))->toBeNull();

    // The client lower-cases the host, so compare without case.
    Http::assertSent(fn ($request): bool => str_starts_with(strtolower((string) $request->url()), strtolower($url)));
})->with([
    'loopback literal' => ['http://127.0.0.1:9/v1'],
    'localhost, trailing dot' => ['http://LOCALHOST.:9/v1'],
    'ipv6 loopback' => ['http://[::1]:9/v1'],
    'unique-local ipv6' => ['http://[fd00::1]/v1'],
    'metadata ip' => ['http://169.254.169.254/v1'],
    'rfc1918' => ['http://10.0.0.5/v1'],
    'userinfo is not a shape rule' => ['http://user:pass@10.0.0.5/v1'],
]);
