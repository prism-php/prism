<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\ModelListingException;
use Prism\Prism\Facades\Prism;

beforeEach(function (): void {
    Http::preventStrayRequests();
    foreach ([Provider::Replicate, Provider::Anthropic, Provider::Gemini] as $provider) {
        config()->set("prism.providers.{$provider->value}.api_key", 'fixture-secret');
        config()->set("prism.providers.{$provider->value}.url", 'https://fixture.test/v1');
    }
    config()->set('prism.providers.gemini.url', 'https://fixture.test/v1beta/models');
});

it('keeps cursor decoding inside the typed listing failure boundary', function (string $cursor, bool $reject, ?string $decoded): void {
    Http::fakeSequence()
        ->push(['results' => [['owner' => 'fixture', 'name' => 'one']], 'next' => 'https://fixture.test/v1/models?cursor='.$cursor])
        ->push(['results' => [['owner' => 'fixture', 'name' => 'two']], 'next' => null]);
    $models = null;
    $caught = null;
    try {
        $models = Prism::provider(Provider::Replicate)->listModels();
    } catch (Throwable $failure) {
        $caught = $failure;
    }
    if ($reject) {
        expect($caught)->toBeInstanceOf(ModelListingException::class)
            ->and($caught::class)->toBe(ModelListingException::class)
            ->and($caught->getMessage())->toBe('The provider returned an invalid or incomplete model listing.')
            ->and($caught->getPrevious())->toBeNull()
            ->and($models)->toBeNull();
        foreach ([$cursor, urldecode($cursor), 'fixture-secret', 'fixture.test'] as $private) {
            expect($caught->getMessage())->not->toContain($private);
        }
        Http::assertSentCount(1);
    } else {
        expect($caught)->toBeNull()
            ->and(array_column($models, 'id'))->toBe(['fixture/one', 'fixture/two']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => ($request['cursor'] ?? null) === $decoded);
    }
})->with([
    'opaque ASCII control' => ['opaque', false, 'opaque'],
    'decoded control byte' => ['%00', true, null],
    'decoded non-UTF8 byte' => ['%FF', true, null],
    'broken two-byte sequence' => ['%C3%28', true, null],
    'broken four-byte sequence' => ['%F0%28%8C%28', true, null],
    'valid UTF8 and reserved bytes control' => ['%C3%A9%2B%2F%3D', false, 'é+/='],
]);

it('does not URL-decode opaque Anthropic and Gemini tokens', function (Provider $provider): void {
    $anthropic = $provider === Provider::Anthropic;
    Http::fakeSequence()
        ->push($anthropic
            ? ['data' => [['id' => '%FF']], 'has_more' => true, 'last_id' => '%FF']
            : ['models' => [['name' => 'models/first']], 'nextPageToken' => '%FF'])
        ->push($anthropic
            ? ['data' => [['id' => 'second']], 'has_more' => false]
            : ['models' => [['name' => 'models/second']]]);
    $models = Prism::provider($provider)->listModels();
    expect(array_column($models, 'id'))->toBe([$anthropic ? '%FF' : 'first', 'second']);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => ($request[$anthropic ? 'after_id' : 'pageToken'] ?? null) === '%FF');
})->with([Provider::Anthropic, Provider::Gemini]);
