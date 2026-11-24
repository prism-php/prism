<?php

declare(strict_types=1);

use Prism\Prism\Exceptions\PrismUrlRefused;
use Prism\Prism\Support\PerCallUrl;

function perCallCode(string $url): ?string
{
    try {
        PerCallUrl::assert($url);
    } catch (PrismUrlRefused $refused) {
        return $refused->code();
    }

    return null;
}

it('allows public hosts and public address literals', function (string $url): void {
    expect(perCallCode($url))->toBeNull();
})->with([
    'https://api.openai.com/v1',
    'http://8.8.8.8/v1',
    'http://134744072/v1',          // 8.8.8.8 as one decimal number
    'http://[2606:4700::1111]/v1',
    'https://localhost.example.com/v1',
    'https://metadata.example/v1',
    'https://notlocalhost/v1',
]);

it('refuses every address spelling that is private', function (string $url): void {
    expect(perCallCode($url))->toBe('private_address_refused');
})->with([
    'http://0x7f.0.0.1/',
    'http://0X7F000001/',
    'http://017700000001/',          // octal, one label
    'http://0x/',                    // 0x alone is 0.0.0.0
    'http://0/',
    'http://127.0.1/',
    'http://10.1/',
    'http://a.b.localhost/',
    'http://ip6-localhost/',
    'http://instance-data/',
    'http://metadata/',
    'http://[::]/',
    'http://[0:0:0:0:0:0:0:1]/',
    'http://[::ffff:a9fe:a9fe]/',
    'http://[2002:a9fe:a9fe::]/',
    'http://１２７.０.０.１/',         // full-width digits
]);

it('refuses a numeric-looking host it cannot read as an address, rather than resolve it', function (string $url): void {
    expect(perCallCode($url))->toBe('private_address_refused');
})->with([
    'http://08.0.0.1/',
    'http://1.2.3.4.5/',
    'http://256.0.0.1/',
    'http://1.2.3.256/',
    'http://4294967296/',
    'http://0x100000000/',
    'http://99999999999999999999999/',
    'http://example.123/',
    'http://1..1/',
]);

it('refuses what is not an absolute http or https url', function (string $url): void {
    expect(perCallCode($url))->toBe('scheme_not_allowed');
})->with([
    'ftp://example.com/',
    'file:///etc/passwd',
    'http:/example.com',
    '//example.com/v1',
    'http://',
]);

it('refuses a non-ASCII host with its own code when ext-intl is missing', function (): void {
    $withoutIntl = new class extends PerCallUrl
    {
        protected static function intlAvailable(): bool
        {
            return false;
        }
    };

    try {
        $withoutIntl::assert('https://bücher.example/v1');
        $code = null;
    } catch (PrismUrlRefused $refused) {
        $code = $refused->code();
        expect($refused->getMessage())->not->toContain('bücher');
    }

    expect($code)->toBe('idn_unavailable')
        ->and(fn () => $withoutIntl::assert('https://gateway.example/v1'))->not->toThrow(PrismUrlRefused::class);
});

it('refuses a host that IDNA maps to an escape, after mapping', function (string $url): void {
    expect(perCallCode($url))->toBe('private_address_refused');
})->with([
    'http://ｌｏｃ％６１ｌｈｏｓｔ/',  // full-width letters and percent sign
    'http://loc％61lhost/',
]);

it('does not resolve names: a public name that points at loopback is accepted (known limit)', function (): void {
    expect(perCallCode('http://127.0.0.1.nip.io/v1'))->toBeNull();
});

it('refuses the loopback and local names the stock hosts files carry', function (string $url): void {
    expect(perCallCode($url))->toBe('private_address_refused');
})->with([
    'http://localhost4/',                  // RHEL, CentOS, Fedora, Amazon Linux
    'http://localhost4.localdomain4/',
    'http://LOCALHOST6./',
    'http://localhost6.localdomain6/',
    'http://ipv6-localhost/',              // openSUSE
    'http://ipv6-loopback/',
    'http://ipv6-localnet/',
    'http://ipv6-allhosts/',
    'http://ip6-localnet/',                // Debian, Ubuntu, Docker
    'http://ip6-mcastprefix/',
    'http://ip6-allnodes/',
    'http://ip6-allrouters/',
    'http://broadcasthost/',               // macOS
    'http://box.localhost.localdomain/',   // nss-myhostname
]);

it('refuses IPv6 forms that carry a private IPv4 address', function (string $url): void {
    expect(perCallCode($url))->toBe('private_address_refused');
})->with([
    'http://[::ffff:0:127.0.0.1]/',        // SIIT IPv4-translated
    'http://[::ffff:0:7f00:1]/',
    'http://[::ffff:0:a9fe:a9fe]/',
    'http://[::ffff:0:8.8.8.8]/',          // the whole translated range is refused
    'http://[2001:4860::5efe:7f00:1]/',    // ISATAP, behind a public prefix
    'http://[2001:4860::200:5efe:a9fe:a9fe]/',
    'http://[2001:4860::5efe:10.0.0.5]/',
]);

it('allows an ISATAP address that embeds a public IPv4 behind a public prefix', function (): void {
    expect(perCallCode('http://[2001:4860::5efe:808:808]/v1'))->toBeNull()
        ->and(perCallCode('https://localhost4.example/v1'))->toBeNull()
        ->and(perCallCode('https://xlocalhost6/v1'))->toBeNull();
});

/*
 * The guard on its own refuses a format or invisible character, or a UTS46
 * deviation character, anywhere in the authority. IDNA implementations do not
 * agree on these: an HTTP client that deletes a zero-width joiner contacts
 * `localhost` while a nontransitional conversion reads a public name. No
 * other check is relied on.
 */
it('refuses a format, invisible or deviation character in the host when called directly', function (string $url): void {
    try {
        PerCallUrl::assert($url);
        $refused = null;
    } catch (PrismUrlRefused $error) {
        $refused = $error;
    }

    expect($refused?->code())->toBe('private_address_refused')
        ->and($refused?->getMessage())->toBe(PerCallUrl::HOST_MESSAGE);
})->with([
    'joiner inside localhost' => ["http://loca\u{200D}lhost/"],
    'non-joiner inside localhost' => ["http://loca\u{200C}lhost/"],
    'joiner after localhost' => ["http://localhost\u{200D}/"],
    'joiner before localhost' => ["http://\u{200D}localhost/"],
    'joiner after loopback' => ["http://127.0.0.1\u{200D}/"],
    'joiner inside loopback' => ["http://127.0.0.\u{200D}1/"],
    'non-joiner after loopback' => ["http://127.0.0.1\u{200C}/"],
    'joiner after metadata ip' => ["http://169.254.169.254\u{200D}/"],
    'joiner after private ip' => ["http://10.0.0.5\u{200D}/"],
    'joiner after metadata name' => ["http://metadata.google.internal\u{200D}/"],
    'joiner in a public name' => ["https://gate\u{200D}way.example/v1"],
    'soft hyphen' => ["http://loca\u{00AD}lhost/"],
    'word joiner' => ["http://localhost\u{2060}/"],
    'mongolian todo soft hyphen' => ["http://loca\u{1806}lhost/"],
    'mongolian vowel separator' => ["http://localhost\u{180E}/"],
    'variation selector' => ["http://localhost\u{FE0F}/"],
    'byte order mark' => ["http://127.0.0.1\u{FEFF}/"],
    'left-to-right mark' => ["http://localhost\u{200E}/"],
    'hangul filler' => ["http://localhost\u{3164}/"],
    'tag character' => ["http://localhost\u{E0001}/"],
    'no-break space' => ["http://localhost\u{00A0}/"],
    'sharp s (deviation)' => ["https://fa\u{00DF}.example/v1"],
    'final sigma (deviation)' => ["https://\u{03BB}\u{03BF}\u{03B3}\u{03BF}\u{03C2}.example/v1"],
    'joiner in userinfo' => ["http://user\u{200D}@gateway.example/v1"],
]);
