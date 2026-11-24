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
