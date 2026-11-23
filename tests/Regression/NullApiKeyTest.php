<?php

declare(strict_types=1);

namespace Tests\Regression;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Throwable;
use TypeError;

/**
 * A null api_key and a key dropped by credential scoping are different states.
 *
 * A key configured as null is a misconfiguration and is refused before any
 * request. A configured key that a per-call url leaves behind is not an error:
 * the call goes to the new endpoint and carries no key.
 */

/** @return array<string, array{0: string}> */
function nullKeyProviders(): array
{
    return [
        'anthropic' => ['anthropic'],
        'gemini' => ['gemini'],
        'groq' => ['groq'],
        'mistral' => ['mistral'],
        'xai' => ['xai'],
        'z' => ['z'],
    ];
}

/** @param array<string, mixed> $overrides */
function callWithKeyConfig(string $name, array $overrides = []): mixed
{
    return Prism::text()
        ->using($name, 'fixture-model', $overrides)
        ->withPrompt('hi')
        ->asText();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<int, string>
 */
function requestsAfterCall(string $name, array $overrides): array
{
    try {
        callWithKeyConfig($name, $overrides);
    } catch (TypeError $error) {
        throw $error;
    } catch (Throwable) {
        // The faked 500 fails the call; only the outgoing request matters.
    }

    return Http::recorded()
        ->map(function (array $pair): string {
            /** @var Request $request */
            $request = $pair[0];
            $headers = collect($request->headers())
                ->map(fn (array $values, string $key): string => $key.': '.implode(',', $values))
                ->implode("\n");

            return $request->url()."\n".$headers."\n".$request->body();
        })
        ->values()
        ->all();
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => ['message' => 'fixture']], 500)]);
});

it('refuses a key configured as null before any request', function (string $name): void {
    config()->set(["prism.providers.{$name}.api_key" => null]);

    expect(fn (): mixed => callWithKeyConfig($name))->toThrow(TypeError::class);

    Http::assertNothingSent();
})->with(nullKeyProviders());

it('refuses a null key passed for the call', function (string $name): void {
    config()->set("prism.providers.{$name}.api_key", 'configured-secret');

    expect(fn (): mixed => callWithKeyConfig($name, ['api_key' => null]))->toThrow(TypeError::class);

    Http::assertNothingSent();
})->with(nullKeyProviders());

it('refuses a null key passed alongside a different url', function (string $name): void {
    config()->set("prism.providers.{$name}.api_key", 'configured-secret');

    expect(fn (): mixed => callWithKeyConfig($name, ['url' => 'https://other-host.example/v1', 'api_key' => null]))
        ->toThrow(TypeError::class);

    Http::assertNothingSent();
})->with(nullKeyProviders());

it('sends no key and does not throw when a per-call url drops the configured key', function (string $name): void {
    config()->set("prism.providers.{$name}.api_key", 'configured-secret');

    $requests = requestsAfterCall($name, ['url' => 'https://other-host.example/v1']);

    expect($requests)->not->toBeEmpty();

    foreach ($requests as $request) {
        expect($request)->toContain('other-host.example');
        expect($request)->not->toContain('configured-secret');
    }
})->with(nullKeyProviders());

it('does not throw when a per-call url replaces a provider whose configured key is null', function (string $name): void {
    config()->set(["prism.providers.{$name}.api_key" => null]);

    $requests = requestsAfterCall($name, ['url' => 'https://other-host.example/v1']);

    expect($requests)->not->toBeEmpty();
})->with(nullKeyProviders());
