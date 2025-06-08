<?php

declare(strict_types=1);

use Prism\Prism\Http\Factory;
use Prism\Prism\Http\PendingRequest;
use Prism\Prism\Http\Response;

it('creates pending request', function (): void {
    $factory = new Factory;
    $pendingRequest = $factory->createPendingRequest();

    expect($pendingRequest)->toBeInstanceOf(PendingRequest::class);
});

it('delegates method calls to pending request', function (): void {
    $factory = new Factory;

    $pendingRequest = $factory->withHeaders(['Authorization' => 'Bearer token']);

    expect($pendingRequest)->toBeInstanceOf(PendingRequest::class);
});

it('can fake responses', function (): void {
    $factory = new Factory;

    $factory->fake([
        'example.com/*' => $factory::response(['message' => 'success'], 200),
    ]);

    $response = $factory->get('https://example.com/test');

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->json('message'))->toBe('success');
});

it('can assert sent requests', function (): void {
    $factory = new Factory;

    $factory->fake();

    $factory->get('https://example.com/test');

    $factory->assertSent(fn ($request): bool => $request->url() === 'https://example.com/test');
});

it('can assert request count', function (): void {
    $factory = new Factory;

    $factory->fake();

    $factory->get('https://example.com/test1');
    $factory->get('https://example.com/test2');

    $factory->assertSentCount(2);
});

it('can assert nothing sent', function (): void {
    $factory = new Factory;

    $factory->fake();

    $factory->assertNothingSent();
});

it('can create response sequences', function (): void {
    $factory = new Factory;

    $sequence = $factory->sequence([
        $factory::response(['page' => 1], 200),
        $factory::response(['page' => 2], 200),
    ]);

    $factory->fake([
        'example.com/*' => $sequence,
    ]);

    $response1 = $factory->get('https://example.com/page/1');
    $response2 = $factory->get('https://example.com/page/2');

    expect($response1->json('page'))->toBe(1);
    expect($response2->json('page'))->toBe(2);
});

it('can prevent stray requests', function (): void {
    $factory = new Factory;

    $factory->preventStrayRequests();

    expect($factory->preventingStrayRequests())->toBeTrue();

    $factory->allowStrayRequests();

    expect($factory->preventingStrayRequests())->toBeFalse();
});

it('can add global middleware', function (): void {
    $factory = new Factory;

    $middleware = fn ($handler): \Closure => fn ($request, $options) => $handler($request, $options);

    $factory->globalMiddleware($middleware);

    expect($factory->getGlobalMiddleware())->toHaveCount(1);
});

it('can set global options', function (): void {
    $factory = new Factory;

    $options = ['timeout' => 30];

    $factory->globalOptions($options);

    $pendingRequest = $factory->createPendingRequest();

    expect($pendingRequest->getOptions()['timeout'])->toBe(30);
});
