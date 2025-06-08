<?php

declare(strict_types=1);

use Prism\Prism\Facades\Http;
use Prism\Prism\Http\Factory;
use Prism\Prism\Http\PendingRequest;

it('resolves to factory', function (): void {
    $factory = Http::getFacadeRoot();

    expect($factory)->toBeInstanceOf(Factory::class);
});

it('can create pending requests via facade', function (): void {
    $pendingRequest = Http::withHeaders(['Authorization' => 'Bearer token']);

    expect($pendingRequest)->toBeInstanceOf(PendingRequest::class);
});

it('can fake requests via facade', function (): void {
    Http::fake([
        'example.com/*' => Http::response(['message' => 'success'], 200),
    ]);

    $response = Http::get('https://example.com/test');

    expect($response->json('message'))->toBe('success');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/test');
});
