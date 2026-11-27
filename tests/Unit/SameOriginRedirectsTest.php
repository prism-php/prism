<?php

declare(strict_types=1);

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Prism\Prism\Exceptions\PrismUrlRefused;
use Prism\Prism\Support\SameOriginRedirects;

function sameOriginRedirectOutcome(string $from, string $location, int $status = 302, array $options = ['allow_redirects' => true]): string
{
    $handler = fn (): mixed => Create::promiseFor(new Response($status, ['Location' => $location]));
    $middleware = SameOriginRedirects::middleware();

    try {
        $middleware($handler)(new Request('GET', $from), $options)->wait();
    } catch (PrismUrlRefused $e) {
        expect($e->code())->toBe('redirect_refused');

        return 'refused';
    }

    return 'passed';
}

it('lets a redirect to :dataset through', function (string $from, string $location): void {
    expect(sameOriginRedirectOutcome($from, $location))->toBe('passed');
})->with([
    'a relative path' => ['https://api.example.com/v1/a', 'b'],
    'an absolute path' => ['https://api.example.com/v1/a', '/v2/a?x=1'],
    'an empty location' => ['https://api.example.com/v1/a', ''],
    'the same origin' => ['https://api.example.com/v1/a', 'https://api.example.com/v2/a'],
    'the same origin with the default port' => ['https://api.example.com/v1/a', 'https://api.example.com:443/v2/a'],
    'the same origin in upper case' => ['https://api.example.com/v1/a', 'HTTPS://API.EXAMPLE.COM/v2/a'],
    'the same origin, scheme-relative' => ['https://api.example.com/v1/a', '//api.example.com/v2/a'],
    'the same IPv6 origin' => ['http://[::1]:8080/a', 'http://[::1]:8080/b'],
]);

it('refuses a redirect to :dataset', function (string $from, string $location): void {
    expect(sameOriginRedirectOutcome($from, $location))->toBe('refused');
})->with([
    'another host' => ['https://api.example.com/v1/a', 'https://other.example.com/v1/a'],
    'a subdomain' => ['https://api.example.com/v1/a', 'https://eu.api.example.com/v1/a'],
    'another port' => ['https://api.example.com/v1/a', 'https://api.example.com:8443/v1/a'],
    'another scheme' => ['https://api.example.com/v1/a', 'http://api.example.com/v1/a'],
    'another host, scheme-relative' => ['https://api.example.com/v1/a', '//other.example.com/v1/a'],
    'a host and port with no scheme' => ['https://api.example.com/v1/a', 'other.example.com:8080'],
    'user info and another host' => ['https://api.example.com/v1/a', 'https://api.example.com@other.example.com/'],
    'another IPv6 host' => ['http://[::1]:8080/a', 'http://[::2]:8080/b'],
    'a location that cannot be parsed' => ['https://api.example.com/v1/a', 'http:///x'],
]);

it('leaves the response alone when redirects are not being followed', function (): void {
    expect(sameOriginRedirectOutcome('https://api.example.com/a', 'https://other.example.com/', 302, ['allow_redirects' => false]))->toBe('passed')
        ->and(sameOriginRedirectOutcome('https://api.example.com/a', 'https://other.example.com/', 200))->toBe('passed');
});

it('keeps control characters from the location out of the refusal', function (): void {
    $handler = fn (): mixed => Create::promiseFor(new Response(302, ['Location' => 'https://other%0Aforged.example.com/']));

    try {
        SameOriginRedirects::middleware()($handler)(new Request('GET', 'https://api.example.com/v1/a'), ['allow_redirects' => true])->wait();
        $this->fail('The redirect was not refused.');
    } catch (PrismUrlRefused $e) {
        expect($e->getMessage())->not->toContain("\n");
    }
});

it('names only the origins in the refusal', function (): void {
    $handler = fn (): mixed => Create::promiseFor(new Response(302, ['Location' => 'https://u:p@other.example.com/path?key=sk-test-redirect']));

    try {
        SameOriginRedirects::middleware()($handler)(new Request('GET', 'https://api.example.com/v1/a?key=sk-test-redirect'), ['allow_redirects' => true])->wait();
        $this->fail('The redirect was not refused.');
    } catch (PrismUrlRefused $e) {
        expect($e->getMessage())
            ->toContain('https://api.example.com:443')
            ->toContain('https://other.example.com:443')
            ->not->toContain('sk-test-redirect');
        expect($e->getMessage())->not->toContain('/path');
        expect($e->getMessage())->not->toContain('u:p');
    }
});
