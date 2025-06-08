<?php

declare(strict_types=1);

use Prism\Prism\Http\PendingRequest;

it('can be created', function (): void {
    $request = new PendingRequest;

    expect($request)->toBeInstanceOf(PendingRequest::class);
});

it('can set base url', function (): void {
    $request = new PendingRequest;

    $request->baseUrl('https://api.example.com');

    expect($request)->toBeInstanceOf(PendingRequest::class);
});

it('can set headers', function (): void {
    $request = new PendingRequest;

    $request->withHeaders(['Authorization' => 'Bearer token']);

    $options = $request->getOptions();

    expect($options['headers']['Authorization'])->toBe('Bearer token');
});

it('can set single header', function (): void {
    $request = new PendingRequest;

    $request->withHeader('Content-Type', 'application/json');

    $options = $request->getOptions();

    expect($options['headers']['Content-Type'])->toBe('application/json');
});

it('can set basic auth', function (): void {
    $request = new PendingRequest;

    $request->withBasicAuth('username', 'password');

    $options = $request->getOptions();

    expect($options['auth'])->toBe(['username', 'password']);
});

it('can set token auth', function (): void {
    $request = new PendingRequest;

    $request->withToken('secret-token');

    $options = $request->getOptions();

    expect($options['headers']['Authorization'])->toBe('Bearer secret-token');
});

it('can set timeout', function (): void {
    $request = new PendingRequest;

    $request->timeout(60);

    $options = $request->getOptions();

    expect($options['timeout'])->toBe(60);
});

it('can set connect timeout', function (): void {
    $request = new PendingRequest;

    $request->connectTimeout(10);

    $options = $request->getOptions();

    expect($options['connect_timeout'])->toBe(10);
});

it('can disable redirects', function (): void {
    $request = new PendingRequest;

    $request->withoutRedirecting();

    $options = $request->getOptions();

    expect($options['allow_redirects'])->toBeFalse();
});

it('can disable ssl verification', function (): void {
    $request = new PendingRequest;

    $request->withoutVerifying();

    $options = $request->getOptions();

    expect($options['verify'])->toBeFalse();
});

it('can set retry options', function (): void {
    $request = new PendingRequest;

    $request->retry(3, 1000);

    expect($request)->toBeInstanceOf(PendingRequest::class);
});

it('can set as json', function (): void {
    $request = new PendingRequest;

    $request->asJson();

    $options = $request->getOptions();

    expect($options['headers']['Content-Type'])->toBe('application/json');
});

it('can set as form', function (): void {
    $request = new PendingRequest;

    $request->asForm();

    $options = $request->getOptions();

    expect($options['headers']['Content-Type'])->toBe('application/x-www-form-urlencoded');
});

it('can accept json', function (): void {
    $request = new PendingRequest;

    $request->acceptJson();

    $options = $request->getOptions();

    expect($options['headers']['Accept'])->toBe('application/json');
});

it('can set user agent', function (): void {
    $request = new PendingRequest;

    $request->withUserAgent('Prism/1.0');

    $options = $request->getOptions();

    expect($options['headers']['User-Agent'])->toBe('Prism/1.0');
});

it('can set url parameters', function (): void {
    $request = new PendingRequest;

    $request->withUrlParameters(['id' => 123]);

    expect($request)->toBeInstanceOf(PendingRequest::class);
});
