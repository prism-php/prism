<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\ModelListingAuthenticationException;
use Prism\Prism\Exceptions\ModelListingException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Models\Listing;

beforeEach(function (): void {
    Http::preventStrayRequests();

    foreach (Provider::cases() as $provider) {
        config()->set("prism.providers.{$provider->value}.api_key", 'fixture-secret-key');
        config()->set("prism.providers.{$provider->value}.url", 'https://fixture-secret-host.test/v1');
    }

    config()->set('prism.providers.azure.url', 'https://fixture-secret-host.test/openai/v1');
});

it('distinguishes rejected credentials or access without retaining provider details', function (int $status, Provider $provider): void {
    Http::fakeSequence()->push('fixture-private-body?api_key=fixture-secret-key', $status);

    try {
        Prism::provider($provider)->listModels();
        test()->fail('Expected authentication rejection');
    } catch (ModelListingException $exception) {
        expect($exception::class)->toBe(ModelListingAuthenticationException::class);
        expect($exception->getMessage())->toBe('The provider rejected credentials or access for model listing.');
        expect($exception->getPrevious())->toBeNull();
        expect($exception->httpStatus)->toBeNull();
        expect($exception->responseBody)->toBeNull();

        foreach (['fixture-secret-key', 'fixture-private-body', 'fixture-secret-host'] as $private) {
            expect($exception->getMessage())->not->toContain($private);
            expect(json_encode($exception, JSON_THROW_ON_ERROR))->not->toContain($private);
        }
    }

    Http::assertSentCount(1);
})->with([401, 403])->with([
    Provider::OpenAI, Provider::Anthropic, Provider::Azure, Provider::DeepSeek,
    Provider::Groq, Provider::Mistral, Provider::XAI, Provider::OpenRouter,
    Provider::Requesty, Provider::Gemini, Provider::Ollama, Provider::ElevenLabs,
    Provider::Replicate, Provider::Qwen,
]);

it('classifies an HTTP client request exception without chaining its private response', function (int $status): void {
    Http::fake(function () use ($status): never {
        throw new RequestException(new Response(new PsrResponse($status, [], 'fixture-private-body fixture-secret-key')));
    });

    try {
        Prism::provider(Provider::OpenAI)->listModels();
        test()->fail('Expected authentication rejection');
    } catch (ModelListingException $exception) {
        expect($exception::class)->toBe(ModelListingAuthenticationException::class);
        expect($exception->getMessage())->toBe('The provider rejected credentials or access for model listing.');
        expect($exception->getPrevious())->toBeNull();
        expect($exception->responseBody)->toBeNull();
        expect(json_encode($exception, JSON_THROW_ON_ERROR))->not->toContain('fixture-private-body');
        expect(json_encode($exception, JSON_THROW_ON_ERROR))->not->toContain('fixture-secret-key');
    }
})->with([401, 403]);

it('handles non-throwing HTTP clients with the same private authentication rejection', function (int $status): void {
    Http::fakeSequence()->push('fixture-private-body fixture-secret-key', $status);
    $listing = new Listing(Http::baseUrl('https://fixture-secret-host.test/v1')->withToken('fixture-secret-key'));

    try {
        $listing->all();
        test()->fail('Expected authentication rejection from the returned response');
    } catch (ModelListingException $exception) {
        expect($exception::class)->toBe(ModelListingAuthenticationException::class);
        expect($exception->getMessage())->toBe('The provider rejected credentials or access for model listing.');
        expect($exception->getPrevious())->toBeNull();
        expect($exception->responseBody)->toBeNull();
        expect(json_encode($exception, JSON_THROW_ON_ERROR))->not->toContain('fixture-private-body');
        expect(json_encode($exception, JSON_THROW_ON_ERROR))->not->toContain('fixture-secret-key');
    }

    Http::assertSentCount(1);
})->with([401, 403]);

it('preserves generic failures for other HTTP statuses', function (int $status): void {
    Http::fakeSequence()->push('fixture-private-body fixture-secret-key', $status);

    try {
        Prism::provider(Provider::OpenAI)->listModels();
        test()->fail('Expected listing failure');
    } catch (ModelListingException $exception) {
        expect($exception::class)->toBe(ModelListingException::class);
        expect($exception->getMessage())->toBe('The provider model listing request failed.');
        expect($exception->getPrevious())->toBeNull();
        expect($exception->responseBody)->toBeNull();
        expect(json_encode($exception, JSON_THROW_ON_ERROR))->not->toContain('fixture-private-body');
    }

    Http::assertSentCount(1);
})->with([301, 302, 400, 404, 408, 429, 500, 503]);

it('never returns earlier pages when a later page rejects authentication', function (int $status): void {
    Http::fakeSequence()
        ->push(['data' => [['id' => 'first']], 'has_more' => true, 'last_id' => 'first'])
        ->push('fixture-private-body fixture-secret-key', $status);

    try {
        Prism::provider(Provider::Anthropic)->listModels();
        test()->fail('Expected authentication rejection after the first page');
    } catch (ModelListingException $exception) {
        expect($exception::class)->toBe(ModelListingAuthenticationException::class);
    }
    Http::assertSentCount(2);
})->with([401, 403]);

it('refuses unexpected continuation signals on every OpenAI-shaped listing', function (Provider $provider): void {
    Http::fakeSequence()->push(['data' => [['id' => 'partial']], 'has_more' => true]);

    expect(fn (): array => Prism::provider($provider)->listModels())
        ->toThrow(ModelListingException::class, 'invalid or incomplete');
    Http::assertSentCount(1);
})->with([
    Provider::OpenAI, Provider::Azure, Provider::DeepSeek, Provider::Groq,
    Provider::Mistral, Provider::XAI, Provider::OpenRouter, Provider::Requesty,
]);

it('does not follow or silently ignore a continuation marker on an unpaginated mapping', function (array $signal): void {
    Http::fakeSequence()->push(['data' => [['id' => 'partial']], ...$signal]);

    try {
        Prism::provider(Provider::OpenAI)->listModels();
        test()->fail('Expected incomplete-list refusal');
    } catch (ModelListingException $exception) {
        expect($exception::class)->toBe(ModelListingException::class);
        expect($exception->getMessage())->toBe('The provider returned an invalid or incomplete model listing.');
        expect($exception->getPrevious())->toBeNull();
        expect(json_encode($exception, JSON_THROW_ON_ERROR))->not->toContain('fixture-private-cursor');
        expect(json_encode($exception, JSON_THROW_ON_ERROR))->not->toContain('attacker.test');
    }

    Http::assertSentCount(1);
})->with([
    'next URL' => [['next' => 'https://attacker.test/models?cursor=fixture-private-cursor']],
    'next object' => [['next' => ['href' => 'https://attacker.test/models']]],
    'nested next link' => [['links' => ['next' => 'https://attacker.test/models']]],
    'empty next is still non-null' => [['next' => '']],
    'false next is still non-null' => [['next' => false]],
    'malformed has_more string' => [['has_more' => 'false']],
    'malformed has_more null' => [['has_more' => null]],
    'malformed has_more zero' => [['has_more' => 0]],
    'malformed has_more object' => [['has_more' => ['unexpected' => true]]],
    'has_more false does not override next' => [['has_more' => false, 'next' => 'https://attacker.test/models']],
    'has_more false does not override nested next' => [['has_more' => false, 'links' => ['next' => 'https://attacker.test/models']]],
]);

it('accepts complete OpenAI-shaped responses with absent or explicit empty continuation state', function (array $state): void {
    Http::fakeSequence()->push(['data' => [['id' => 'complete']], ...$state]);

    expect(array_column(Prism::provider(Provider::OpenAI)->listModels(), 'id'))->toBe(['complete']);
    Http::assertSentCount(1);
})->with([
    'absent markers' => [[]],
    'explicitly complete' => [['has_more' => false, 'next' => null, 'links' => ['next' => null]]],
]);
