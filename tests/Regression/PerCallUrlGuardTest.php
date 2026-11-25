<?php

declare(strict_types=1);

namespace Tests\Regression;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismUrlRefused;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Support\PerCallUrl;
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

/**
 * A host is judged as written. Some HTTP clients percent-decode a host before
 * they connect, so an escape could carry a private address past a check that
 * reads the text; the guard never decodes, it refuses. Any character a
 * hostname or address literal may not hold is refused the same way, and so is
 * a backslash in the authority, which URL parsers split in different places.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function escapedOrIllegalPerCallHosts(): array
{
    return [
        'escaped letter in localhost' => ['http://loc%61lhost:8080/admin', 'loc%61lhost'],
        'escaped letter, upper case' => ['http://LOC%41LHOST/v1', 'loc%41lhost'],
        'localhost, escaped trailing dot' => ['http://localhost%2e/v1', 'localhost%2e'],
        'loopback, escaped trailing dot' => ['http://127.0.0.1%2e/v1', '127.0.0.1%2e'],
        'loopback, escaped NUL before a public suffix' => ['http://127.0.0.1%00.evil.com/v1', '%00'],
        'loopback, every digit escaped' => ['http://%31%32%37.0.0.1/v1', '%31'],
        'metadata ip, escaped trailing dot' => ['http://169.254.169.254%2e/latest/meta-data/', '169.254'],
        'metadata name, escaped trailing dot' => ['http://metadata.google.internal%2e/x', 'metadata.google'],
        'public name with an escaped dot' => ['https://gateway%2eexample/v1', 'gateway'],
        'underscore in a name' => ['https://gate_way.example/v1', 'gate_way'],
        'backslash before userinfo' => ['http://127.0.0.1\@gateway.example/v1', 'gateway.example'],
        'backslash after a public name' => ['http://gateway.example\@127.0.0.1/v1', '127.0.0.1'],
        'ipv6 zone id' => ['http://[fe80::1%25eth0]/v1', 'eth0'],
        'escaped ipv6 digit' => ['http://[::%31]/v1', '%31'],
    ];
}

it('refuses a per-call host that holds an escape or a character no host may hold, before any request', function (string $url, string $fragment): void {
    $refused = perCallRefusal('openai', ['url' => $url]);

    expect($refused)->toBeInstanceOf(PrismUrlRefused::class)
        ->and($refused?->code())->toBe('private_address_refused')
        ->and(strtolower((string) $refused?->getMessage()))->not->toContain(strtolower($fragment));

    Http::assertNothingSent();
})->with(escapedOrIllegalPerCallHosts());

it('reads a trailing dot as the host the client contacts', function (string $url): void {
    expect(perCallRefusal('openai', ['url' => $url])?->code())->toBe('private_address_refused');

    Http::assertNothingSent();
})->with([
    'loopback' => ['http://127.0.0.1./v1'],
    'hex loopback' => ['http://0x7f000001./v1'],
    'localhost, two dots' => ['http://localhost../v1'],
    'metadata ip' => ['http://169.254.169.254./latest'],
]);

it('sends to a public per-call host with a trailing dot or an international name in punycode', function (string $url): void {
    expect(perCallRefusal('openai', ['url' => $url]))->toBeNull();

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), $url));
})->with([
    'trailing dot' => ['https://gateway.example./v1'],
    'international name in punycode' => ['https://xn--bcher-kva.example/v1'],
    'sharp s name in punycode' => ['https://xn--strae-oqa.de/v1'],
    'persian name with a non-joiner, in punycode' => ['https://xn--mgbn2ecje63gr19l.ir/v1'],
    'arabic label beside a digit-led label, in punycode' => ['https://xn--mgbh0fb.3com.example/v1'],
    'hyphens in positions 3 and 4' => ['https://xn--bcher-kva.ab--cd.example/v1'],
]);

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

it('refuses the stock hosts-file names and IPv4-carrying IPv6 forms, before any request', function (string $url, string $fragment): void {
    $refused = perCallRefusal('openai', ['url' => $url]);

    expect($refused?->code())->toBe('private_address_refused')
        ->and(strtolower((string) $refused?->getMessage()))->not->toContain(strtolower($fragment));

    Http::assertNothingSent();
})->with([
    'localhost4' => ['http://localhost4:8080/v1', 'localhost4'],
    'localhost4.localdomain4' => ['http://localhost4.localdomain4/v1', 'localdomain4'],
    'localhost6' => ['http://localhost6:8080/v1', 'localhost6'],
    'localhost6.localdomain6' => ['http://LOCALHOST6.LOCALDOMAIN6./v1', 'localdomain6'],
    'ipv6-localhost' => ['http://ipv6-localhost/v1', 'ipv6-localhost'],
    'broadcasthost' => ['http://broadcasthost/v1', 'broadcasthost'],
    'name under localhost.localdomain' => ['http://box.localhost.localdomain/v1', 'box.'],
    'siit loopback' => ['http://[::ffff:0:127.0.0.1]/v1', 'ffff:0'],
    'siit metadata' => ['http://[::ffff:0:a9fe:a9fe]/v1', 'a9fe'],
    'isatap loopback' => ['http://[2001:4860::5efe:7f00:1]/v1', '5efe'],
]);

it('refuses a format, invisible or deviation character in a per-call host, before any request', function (string $url): void {
    $refused = perCallRefusal('openai', ['url' => $url]);

    expect($refused?->code())->toBe('private_address_refused')
        ->and($refused?->getMessage())->toBe(PerCallUrl::HOST_MESSAGE);

    Http::assertNothingSent();
})->with([
    'soft hyphen inside localhost' => ["http://loca\u{00AD}lhost/v1"],
    'word joiner after metadata ip' => ["http://169.254.169.254\u{2060}/latest"],
    'mongolian todo soft hyphen' => ["http://loca\u{1806}lhost/v1"],
    'variation selector' => ["http://localhost\u{FE0F}/v1"],
    'sharp s in a public name' => ["https://fa\u{00DF}.example/v1"],
    'final sigma in a public name' => ["https://\u{03BB}\u{03BF}\u{03B3}\u{03BF}\u{03C2}.example/v1"],
]);

it('refuses a zero-width joiner in a per-call host before any request', function (string $url): void {
    expect(fn () => Prism::text()->using('openai', 'fixture-model', ['url' => $url])->withPrompt('hi')->asText())
        ->toThrow(\InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'inside localhost' => ["http://loca\u{200D}lhost/v1"],
    'after loopback' => ["http://127.0.0.1\u{200D}/v1"],
    'non-joiner after loopback' => ["http://127.0.0.1\u{200C}/v1"],
]);

/*
 * A per-call host is ASCII. IDNA converters disagree on which name some
 * characters spell, so an international name is given in its punycode form,
 * which every client sends unchanged. The unicode spelling is refused with its
 * own code, in all three languages, whatever the name.
 */
it('refuses a non-ASCII per-call host with its own code, before any request', function (string $url, string $fragment): void {
    $refused = perCallRefusal('openai', ['url' => $url]);

    expect($refused?->code())->toBe('non_ascii_host_use_punycode')
        ->and($refused?->getMessage())->toBe(PerCallUrl::PUNYCODE_MESSAGE)
        ->and((string) $refused?->getMessage())->not->toContain($fragment);

    Http::assertNothingSent();
})->with([
    'international name' => ["https://b\u{00FC}cher.example/v1", "b\u{00FC}cher"],
    'cyrillic name' => ["https://\u{043F}\u{0440}\u{0438}\u{043C}\u{0435}\u{0440}.\u{0440}\u{0444}/v1", "\u{0440}\u{0444}"],
    'arabic label beside a digit-led label' => ["https://\u{0645}\u{062B}\u{0627}\u{0644}.3com.example/v1", '3com'],
    'hyphens in positions 3 and 4' => ["https://b\u{00FC}cher.ab--cd.example/v1", 'ab--cd'],
    'full-width loopback' => ["http://\u{FF11}\u{FF12}\u{FF17}.\u{FF10}.\u{FF10}.\u{FF11}/v1", "\u{FF11}"],
]);

it('refuses a capital sharp s in a per-call host, before any request', function (string $url): void {
    $refused = perCallRefusal('openai', ['url' => $url]);

    expect($refused?->code())->toBe('private_address_refused')
        ->and($refused?->getMessage())->toBe(PerCallUrl::HOST_MESSAGE);

    Http::assertNothingSent();
})->with([
    'inside localhost' => ["http://loca\u{1E9E}lhost/v1"],
    'after loopback' => ["http://127.0.0.1\u{1E9E}/v1"],
    'in a public name' => ["https://gateway\u{1E9E}.example/v1"],
]);

/*
 * An IPv6 literal may end in a dotted IPv4 address. It is the same address as
 * its hex spelling and is judged the same way: refused when it, or the IPv4
 * address it carries, is private.
 */
it('sends to a public IPv6 literal written with a dotted IPv4 tail', function (string $url): void {
    expect(perCallRefusal('openai', ['url' => $url]))->toBeNull();

    Http::assertSentCount(1);
})->with([
    'plain' => ['http://[2606:4700::8.8.8.8]/v1'],
    'after a group' => ['http://[2606:4700::1:8.8.8.8]/v1'],
    'isatap embedding a public ipv4' => ['http://[2606:4700::5efe:8.8.8.8]/v1'],
]);

it('refuses an IPv6 literal whose dotted IPv4 tail is private, before any request', function (string $url): void {
    expect(perCallRefusal('openai', ['url' => $url])?->code())->toBe('private_address_refused');

    Http::assertNothingSent();
})->with([
    'isatap loopback' => ['http://[2606:4700::5efe:127.0.0.1]/v1'],
    'nat64 metadata' => ['http://[64:ff9b::169.254.169.254]/latest'],
    'siit private' => ['http://[::ffff:0:10.0.0.5]/v1'],
    'ipv4-compatible loopback' => ['http://[::127.0.0.1]/v1'],
]);

it('refuses an ISATAP address behind a 6to4 prefix that carries a private IPv4, before any request', function (string $url): void {
    expect(perCallRefusal('openai', ['url' => $url])?->code())->toBe('private_address_refused');

    Http::assertNothingSent();
})->with([
    'loopback' => ['http://[2002:808:808::5efe:7f00:1]/v1'],
    'metadata, dotted' => ['http://[2002:808:808::5efe:169.254.169.254]/latest'],
    'trailing dot inside brackets' => ['http://[2606:4700::127.0.0.1.]/v1'],
]);
