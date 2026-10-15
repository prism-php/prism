<?php

declare(strict_types=1);

namespace Tests\ValueObjects\Media;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismUrlRefused;
use Prism\Prism\Support\HostResolver;
use Prism\Prism\ValueObjects\Media\Image;

/*
|--------------------------------------------------------------------------
| prism#44: an opt-in guarded fetch
|--------------------------------------------------------------------------
|
| `fetchUrlContent()` is deliberately unguarded and says so. The migration it
| invites is the dangerous one: code that broke when implicit fetching was
| removed gets `->fetchUrlContent()` added wherever it failed, often on a URL
| that came from a request or from model output. That is server-side request
| forgery, now written as an explicit line.
|
| `fetchPublicUrlContent()` is for callers who cannot trust the URL. Every test
| here asks what a HOSTILE url does, because the well-behaved one tells us
| almost nothing.
|
*/

/** Resolve any host to whatever the test says, so no test touches real DNS. */
function resolvesTo(array $map): void
{
    app()->instance(HostResolver::class, new class($map) implements HostResolver
    {
        public function __construct(private array $map) {}

        public function resolve(string $host): array
        {
            return $this->map[$host] ?? [];
        }
    });
}

it('refuses the cloud metadata endpoint, the address this exists for', function (): void {
    // 169.254.169.254 hands out role credentials to anything inside the
    // network that asks. It is link-local, so the IP check alone refuses it.
    Http::fake();

    expect(fn (): Image => Image::fromUrl('http://169.254.169.254/latest/meta-data/iam/security-credentials/')
        ->fetchPublicUrlContent())
        ->toThrow(PrismException::class);

    Http::assertNothingSent();
});

it('refuses a private or loopback literal', function (string $url): void {
    Http::fake();

    expect(fn (): Image => Image::fromUrl($url)->fetchPublicUrlContent())->toThrow(PrismException::class);

    Http::assertNothingSent();
})->with([
    'loopback' => ['http://127.0.0.1/x.png'],
    'loopback by name' => ['http://localhost/x.png'],
    'private 10/8' => ['http://10.0.0.5/x.png'],
    'private 192.168' => ['http://192.168.1.10/x.png'],
    'ipv6 loopback' => ['http://[::1]/x.png'],
    'ipv6 unique local' => ['http://[fd00::1]/x.png'],
]);

it('refuses a public NAME that resolves to a private address', function (): void {
    // The bypass the IP check alone misses: an attacker controls the DNS
    // record, so the host looks public and the connection is internal.
    resolvesTo(['evil.test' => ['10.0.0.5']]);
    Http::fake();

    expect(fn (): Image => Image::fromUrl('http://evil.test/x.png')->fetchPublicUrlContent())
        ->toThrow(PrismException::class);

    Http::assertNothingSent();
});

it('refuses a host that resolves to nothing', function (): void {
    resolvesTo([]);
    Http::fake();

    expect(fn (): Image => Image::fromUrl('http://nowhere.test/x.png')->fetchPublicUrlContent())
        ->toThrow(PrismException::class);
});

it('refuses a scheme that is not http or https', function (string $url): void {
    Http::fake();

    expect(fn (): Image => Image::fromUrl($url)->fetchPublicUrlContent())->toThrow(PrismException::class);

    Http::assertNothingSent();
})->with([
    'file' => ['file:///etc/passwd'],
    'gopher' => ['gopher://example.test/x'],
    'no scheme' => ['//example.test/x.png'],
]);

it('does not follow a redirect into a private address', function (): void {
    // The check that makes the rest worth having. A public host answering 302
    // to http://127.0.0.1/ defeats a guard that only validates the URL it was
    // given, and redirects are followed by default.
    resolvesTo(['public.test' => ['93.184.216.34']]);

    Http::fake([
        'public.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        '169.254.169.254/*' => Http::response('SECRET', 200),
    ]);

    expect(fn (): Image => Image::fromUrl('http://public.test/x.png')->fetchPublicUrlContent())
        ->toThrow(PrismException::class);

    // And it never asked for the internal address at all.
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '169.254.169.254'));
});

it('fetches a genuinely public url', function (): void {
    // The control. A guard that refuses everything would pass every test above.
    resolvesTo(['public.test' => ['93.184.216.34']]);

    Http::fake([
        'public.test/*' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 200),
    ]);

    $image = Image::fromUrl('https://public.test/x.png')->fetchPublicUrlContent();

    expect($image->hasRawContent())->toBeTrue()
        ->and($image->mimeType())->toBe('image/png');
});

it('leaves the unguarded fetch unguarded, which is the documented contract', function (): void {
    // The control for the guard itself: this test failing would mean the guard
    // had been applied to `fetchUrlContent()`, changing behaviour for every
    // existing caller rather than offering them something safer.
    Http::fake([
        '127.0.0.1/*' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 200),
    ]);

    expect(Image::fromUrl('http://127.0.0.1/x.png')->fetchUrlContent()->hasRawContent())->toBeTrue();
});

it('carries a machine-readable code for every refusal', function (string $url, string $code): void {
    // The sentence is for a human and may be reworded freely. The CODE is the
    // contract: a consumer branches on it to decide whether to widen an
    // allow-list, surface a hard stop, or report an SSRF attempt. Without one,
    // every caller is left matching on prose -- and the three languages word
    // these differently on purpose, so prose cannot be compared across a port
    // either. Same reasoning, and the same spelling, as prism-browser's
    // `private_address_refused`.
    resolvesTo(['evil.test' => ['10.0.0.5'], 'nowhere.test' => []]);
    Http::fake();

    try {
        Image::fromUrl($url)->fetchPublicUrlContent();
        expect(false)->toBeTrue('the guard should have refused '.$url);
    } catch (PrismUrlRefused $refused) {
        expect($refused->code())->toBe($code);
    }
})->with([
    'scheme' => ['file:///etc/passwd', 'scheme_not_allowed'],
    'metadata endpoint' => ['http://169.254.169.254/latest/meta-data/', 'private_address_refused'],
    'loopback' => ['http://127.0.0.1/x.png', 'private_address_refused'],
    'name resolving private' => ['http://evil.test/x.png', 'private_address_refused'],
    'unresolvable' => ['http://nowhere.test/x.png', 'host_did_not_resolve'],
]);

it('says a refused REDIRECT is a redirect, not just a bad address', function (): void {
    // A different code from the address refusals, because it is a different
    // finding: the URL the caller passed was fine, and the SERVER aimed them
    // at the metadata endpoint. A consumer seeing this has learned something
    // about the host they were given, not about their own input.
    resolvesTo(['public.test' => ['93.184.216.34']]);

    Http::fake([
        'public.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
    ]);

    try {
        Image::fromUrl('http://public.test/x.png')->fetchPublicUrlContent();
        expect(false)->toBeTrue('the guard should have refused the redirect');
    } catch (PrismUrlRefused $refused) {
        expect($refused->code())->toBe('redirect_refused');
    }
});

it('stops an endless redirect chain at the hop bound', function (): void {
    // THE BOUND IS A DIFFERENT DEFENCE FROM THE DESTINATION CHECK ABOVE, and
    // until G-71 nothing in any language exercised it. Every hop here is a
    // PUBLIC address, so `PublicUrl` is satisfied on every pass and the counter
    // is the only thing that stops the loop. The destination tests cannot cover
    // this: they refuse on hop 1 and never reach the counter at all.
    resolvesTo(['public.test' => ['93.184.216.34']]);

    // Always redirects, never arrives.
    Http::fake([
        'public.test/*' => Http::response('', 302, ['Location' => 'https://public.test/next.png']),
    ]);

    try {
        Image::fromUrl('https://public.test/x.png')->fetchPublicUrlContent();
        expect(false)->toBeTrue('the guard should have refused an endless redirect chain');
    } catch (PrismUrlRefused $refused) {
        expect($refused->code())->toBe('too_many_redirects');
    }

    // Hops 0 through 5 were requested and then it gave up, rather than
    // following the chain until something else happened to stop it.
    Http::assertSentCount(6);
});

it('still follows a chain that stays inside the bound', function (): void {
    // The discrimination control for the test above. A guard that threw
    // `too_many_redirects` on the first hop would satisfy that one perfectly,
    // and an off-by-one here is the likely defect rather than a dramatic
    // failure: five redirects is the documented allowance, so five must work.
    resolvesTo(['public.test' => ['93.184.216.34']]);

    Http::fake([
        'public.test/0.png' => Http::response('', 302, ['Location' => 'https://public.test/1.png']),
        'public.test/1.png' => Http::response('', 302, ['Location' => 'https://public.test/2.png']),
        'public.test/2.png' => Http::response('', 302, ['Location' => 'https://public.test/3.png']),
        'public.test/3.png' => Http::response('', 302, ['Location' => 'https://public.test/4.png']),
        'public.test/4.png' => Http::response('', 302, ['Location' => 'https://public.test/5.png']),
        'public.test/5.png' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 200),
    ]);

    $image = Image::fromUrl('https://public.test/0.png')->fetchPublicUrlContent();

    expect($image->hasRawContent())->toBeTrue()
        ->and($image->mimeType())->toBe('image/png');

    Http::assertSentCount(6);
});

/*
| G-89. Every spelling below was ADMITTED by all three languages, which is why no
| cross-language comparison could see it. filter_var's private and reserved flags
| do not cover CGNAT (Alibaba Cloud's metadata endpoint lives there), and a
| NAT64 or 6to4 address is 169.254.169.254 written in IPv6.
*/
dataset('non-public addresses', [
    'alibaba cloud metadata (CGNAT)' => ['100.100.100.200'],
    'CGNAT' => ['100.64.0.1'],
    'IETF protocol assignments' => ['192.0.0.170'],
    'TEST-NET-1' => ['192.0.2.1'],
    'benchmarking' => ['198.18.0.1'],
    'TEST-NET-2' => ['198.51.100.1'],
    'TEST-NET-3' => ['203.0.113.1'],
    'multicast' => ['224.0.0.1'],
    'NAT64 of the metadata endpoint' => ['64:ff9b::a9fe:a9fe'],
    'NAT64 of the metadata endpoint, dotted' => ['64:ff9b::169.254.169.254'],
    'NAT64 of loopback' => ['64:ff9b::7f00:1'],
    'local-use NAT64' => ['64:ff9b:1::a9fe:a9fe'],
    '6to4 of the metadata endpoint' => ['2002:a9fe:a9fe::'],
    '6to4 of a private address' => ['2002:a00:5::1'],
    'Teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2'],
    'IPv6 documentation' => ['2001:db8::1'],
    'IPv6 discard' => ['100::1'],
    'deprecated site-local' => ['fec0::1'],
    'IPv6 multicast' => ['ff02::1'],
    'IPv4-mapped loopback, compressed' => ['::ffff:7f00:1'],
    'IPv4-compatible loopback, compressed' => ['::7f00:1'],
]);

it('refuses a non-public address written as a literal', function (string $address): void {
    Http::fake();
    $host = str_contains($address, ':') ? "[{$address}]" : $address;

    expect(fn (): Image => Image::fromUrl("http://{$host}/x.png")->fetchPublicUrlContent())
        ->toThrow(PrismUrlRefused::class);

    Http::assertNothingSent();
})->with('non-public addresses');

it('refuses a public name that resolves to a non-public address', function (string $address): void {
    resolvesTo(['evil.test' => [$address]]);
    Http::fake();

    expect(fn (): Image => Image::fromUrl('http://evil.test/x.png')->fetchPublicUrlContent())
        ->toThrow(PrismUrlRefused::class);

    Http::assertNothingSent();
})->with('non-public addresses');

// The controls. An IPv6-only host behind DNS64 is TOLD 64:ff9b:: addresses for
// public sites, so refusing NAT64 outright would break every fetch there. The
// embedded IPv4 is what gets judged.
it('still fetches public addresses, including through NAT64 and 6to4', function (string $address): void {
    resolvesTo(['public.test' => [$address]]);
    Http::fake([
        'public.test/*' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 200),
    ]);

    expect(Image::fromUrl('https://public.test/x.png')->fetchPublicUrlContent()->hasRawContent())->toBeTrue();
})->with([
    'public IPv4' => ['8.8.8.8'],
    'NAT64 of a public IPv4' => ['64:ff9b::808:808'],
    '6to4 of a public IPv4' => ['2002:808:808::1'],
    'public IPv6' => ['2606:4700:4700::1111'],
]);
