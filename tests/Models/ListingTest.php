<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\ModelListingException;
use Prism\Prism\Exceptions\UnsupportedModelListingException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Models\ListModelsRequest;
use Prism\Prism\Models\Model;

beforeEach(function (): void {
    Http::preventStrayRequests();

    foreach (Provider::cases() as $provider) {
        config()->set("prism.providers.{$provider->value}.api_key", 'fixture-secret');
        config()->set("prism.providers.{$provider->value}.url", 'https://fixture.test/v1');
    }
});

it('lists only returned model metadata through the configured provider client', function (
    Provider $provider, string $endpoint, array $payload, array $expected, string $authHeader, string $authValue,
): void {
    if ($provider === Provider::Azure) {
        config()->set('prism.providers.azure.url', 'https://fixture.test/openai/v1');
    }

    config()->set('prism.providers.openai.organization', 'fixture-org');
    config()->set('prism.providers.openai.project', 'fixture-project');
    $optionsSeen = false;

    Http::fake(function (Request $request, array $options) use ($endpoint, $payload, &$optionsSeen) {
        expect($request->url())->toBe($endpoint)
            ->and($request->method())->toBe('GET')
            ->and($options['allow_redirects'])->toBeFalse()
            ->and($options['timeout'])->toBe(9);
        $optionsSeen = true;

        return Http::response($payload);
    });

    $models = Prism::provider($provider)->listModels((new ListModelsRequest)->withClientOptions(['timeout' => 9]));

    expect($models)->toHaveCount(1)
        ->and($models[0])->toBeInstanceOf(Model::class)
        ->and(get_object_vars($models[0]))->toBe($expected)
        ->and($optionsSeen)->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader($authHeader, $authValue));

    if ($provider === Provider::OpenAI) {
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('OpenAI-Organization', 'fixture-org') &&
            $request->hasHeader('OpenAI-Project', 'fixture-project'));
    }
})->with([
    'OpenAI includes non-text and unknown future IDs unchanged' => [Provider::OpenAI, 'https://fixture.test/v1/models', ['data' => [['id' => 'future-audio-id', 'created' => 123, 'owned_by' => 'org', 'secret' => 'discard']]], ['id' => 'future-audio-id', 'displayName' => null, 'createdAt' => 123], 'Authorization', 'Bearer fixture-secret'],
    'DeepSeek' => [Provider::DeepSeek, 'https://fixture.test/v1/models', ['data' => [['id' => 'deepseek-future', 'name' => 'Provider label']]], ['id' => 'deepseek-future', 'displayName' => 'Provider label', 'createdAt' => null], 'Authorization', 'Bearer fixture-secret'],
    'Groq' => [Provider::Groq, 'https://fixture.test/v1/models', ['data' => [['id' => 'groq-future', 'created' => 456]]], ['id' => 'groq-future', 'displayName' => null, 'createdAt' => 456], 'Authorization', 'Bearer fixture-secret'],
    'Mistral' => [Provider::Mistral, 'https://fixture.test/v1/models', ['data' => [['id' => 'mistral-future', 'name' => 'Custom', 'created' => 789]]], ['id' => 'mistral-future', 'displayName' => 'Custom', 'createdAt' => 789], 'Authorization', 'Bearer fixture-secret'],
    'xAI' => [Provider::XAI, 'https://fixture.test/v1/models', ['data' => [['id' => 'grok-future', 'created' => 123]]], ['id' => 'grok-future', 'displayName' => null, 'createdAt' => 123], 'Authorization', 'Bearer fixture-secret'],
    'OpenRouter full-list request omits pagination filters' => [Provider::OpenRouter, 'https://fixture.test/v1/models', ['data' => [['id' => 'author/model', 'name' => 'Returned title', 'created' => 123, 'pricing' => ['prompt' => '999']]]], ['id' => 'author/model', 'displayName' => 'Returned title', 'createdAt' => 123], 'Authorization', 'Bearer fixture-secret'],
    'Requesty' => [Provider::Requesty, 'https://fixture.test/v1/models', ['data' => [['id' => 'vendor/model', 'created' => 123]]], ['id' => 'vendor/model', 'displayName' => null, 'createdAt' => 123], 'Authorization', 'Bearer fixture-secret'],
    'Ollama modified date is not a creation date' => [Provider::Ollama, 'https://fixture.test/v1/api/tags', ['models' => [['name' => 'local-model:tag', 'model' => 'local-model:tag', 'modified_at' => '2026-01-01T00:00:00Z']]], ['id' => 'local-model:tag', 'displayName' => null, 'createdAt' => null], 'Authorization', 'Bearer fixture-secret'],
    'ElevenLabs native array' => [Provider::ElevenLabs, 'https://fixture.test/v1/models', [['model_id' => 'voice-future', 'name' => 'Voice model', 'description' => 'discard']], ['id' => 'voice-future', 'displayName' => 'Voice model', 'createdAt' => null], 'xi-api-key', 'fixture-secret'],
    'Azure v1 does not inherit the legacy api-version query' => [Provider::Azure, 'https://fixture.test/openai/v1/models', ['data' => [['id' => 'deployment-model', 'created' => 123]]], ['id' => 'deployment-model', 'displayName' => null, 'createdAt' => 123], 'api-key', 'fixture-secret'],
]);

it('follows all Anthropic pages and preserves provider labels and dates', function (): void {
    config()->set('prism.providers.anthropic.version', '2023-06-01');
    Http::fakeSequence()
        ->push(['data' => [['id' => 'first', 'display_name' => 'First', 'created_at' => '2026-01-01T00:00:00Z']], 'has_more' => true, 'last_id' => 'first'])
        ->push(['data' => [['id' => 'second', 'display_name' => 'Second']], 'has_more' => true, 'last_id' => 'second'])
        ->push(['data' => [['id' => 'unpriced-future']], 'has_more' => false, 'last_id' => 'unpriced-future']);

    $models = Prism::provider(Provider::Anthropic)->listModels();

    expect(array_column($models, 'id'))->toBe(['first', 'second', 'unpriced-future'])
        ->and($models[0]->displayName)->toBe('First')
        ->and($models[0]->createdAt)->toBe('2026-01-01T00:00:00Z')
        ->and($models[2]->displayName)->toBeNull();
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fixture.test/v1/models?limit=1000&after_id=first' &&
        $request->hasHeader('x-api-key', 'fixture-secret') && $request->hasHeader('anthropic-version', '2023-06-01'));
    Http::assertSent(fn (Request $request): bool => ($request->data()['after_id'] ?? null) === 'second');
});

it('follows Gemini tokens without using an API key in the query', function (): void {
    config()->set('prism.providers.gemini.url', 'https://fixture.test/v1beta/models');
    Http::fakeSequence()
        ->push(['models' => [['name' => 'models/gemini-snapshot-001', 'baseModelId' => 'do-not-replace-snapshot', 'displayName' => 'Actual title']], 'nextPageToken' => 'token+/='])
        ->push(['models' => [['name' => 'models/embedding-future']]]);

    $models = Prism::provider(Provider::Gemini)->listModels();

    expect(array_column($models, 'id'))->toBe(['gemini-snapshot-001', 'embedding-future'])
        ->and($models[0]->displayName)->toBe('Actual title')
        ->and($models[0]->createdAt)->toBeNull();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => ($request->data()['pageToken'] ?? null) === 'token+/=' &&
        ! array_key_exists('key', $request->data()) && $request->hasHeader('x-goog-api-key', 'fixture-secret'));
});

it('follows Qwen page numbers until the returned total is complete', function (): void {
    Http::fakeSequence()
        ->push(['success' => true, 'output' => ['total' => 2, 'page_no' => 1, 'models' => [['model' => 'qwen-new', 'name' => 'New', 'published_time' => '2026-01-01 12:00:00']]]])
        ->push(['success' => true, 'output' => ['total' => 2, 'page_no' => 2, 'models' => [['model' => 'qwen-other']]]]);

    $models = Prism::provider(Provider::Qwen)->listModels();

    expect(array_column($models, 'id'))->toBe(['qwen-new', 'qwen-other'])
        ->and($models[0]->createdAt)->toBe('2026-01-01 12:00:00');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request['page_no'] === 2 && $request['page_size'] === 100);
});

it('follows Replicate cursors only on the original configured endpoint', function (): void {
    Http::fakeSequence()
        ->push(['results' => [['owner' => 'author', 'name' => 'one', 'latest_version' => ['created_at' => 'not-model-creation']]], 'next' => 'https://fixture.test/v1/models?cursor=abc%2B%2F%3D'])
        ->push(['results' => [['owner' => 'other', 'name' => 'two']], 'next' => null]);

    $models = Prism::provider(Provider::Replicate)->listModels();

    expect(array_column($models, 'id'))->toBe(['author/one', 'other/two'])
        ->and($models[0]->createdAt)->toBeNull()
        ->and($models[0]->displayName)->toBeNull();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fixture.test/v1/models?cursor=abc%2B%2F%3D');
});

it('refuses unconfirmed listing capabilities before any HTTP request', function (Provider $provider): void {
    Http::fake();

    expect(fn (): array => Prism::provider($provider)->listModels())->toThrow(UnsupportedModelListingException::class, 'not supported');
    Http::assertNothingSent();
})->with([Provider::Vertex, Provider::Perplexity, Provider::VoyageAI, Provider::Z]);

it('refuses legacy Azure endpoints instead of guessing a listing route', function (): void {
    Http::fake();

    expect(fn (): array => Prism::provider(Provider::Azure)->listModels())->toThrow(UnsupportedModelListingException::class, 'not supported');
    Http::assertNothingSent();
});

it('does not cache, select or filter returned model IDs', function (): void {
    Http::fakeSequence()->push(['data' => [['id' => 'old']]])->push(['data' => [['id' => 'brand-new']]]);
    $provider = Prism::provider(Provider::OpenAI);

    expect(array_column($provider->listModels(), 'id'))->toBe(['old'])
        ->and(array_column($provider->listModels(), 'id'))->toBe(['brand-new']);
    Http::assertSentCount(2);
});

it('accepts an explicitly empty provider roster', function (): void {
    Http::fakeSequence()->push(['data' => []]);

    expect(Prism::provider(Provider::OpenAI)->listModels())->toBe([]);
});

it('does not expose provider errors, request secrets or previous exceptions', function (int $status): void {
    Http::fakeSequence()->push('secret-provider-error?api_key=fixture-secret', $status);

    try {
        Prism::provider(Provider::OpenAI)->listModels();
        test()->fail('Expected a typed listing failure');
    } catch (ModelListingException $exception) {
        expect($exception->getMessage())->toBe('The provider model listing request failed.')
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->httpStatus)->toBeNull()
            ->and($exception->responseBody)->toBeNull();
        expect(json_encode($exception))->not->toContain('fixture-secret');
        expect(json_encode($exception))->not->toContain('secret-provider-error');
    }
})->with([301, 302, 400, 401, 403, 404, 429, 500, 503]);

it('sanitizes connection failures', function (): void {
    Http::fake(['*' => Http::failedConnection('fixture-secret in provider URL')]);

    expect(fn (): array => Prism::provider(Provider::OpenAI)->listModels())->toThrow(ModelListingException::class, 'The provider model listing request failed.');
});

it('rejects malformed payloads rather than returning a guessed or partial roster', function (mixed $payload): void {
    Http::fakeSequence()->push($payload);

    expect(fn (): array => Prism::provider(Provider::OpenAI)->listModels())->toThrow(ModelListingException::class, 'invalid or incomplete');
})->with([
    'invalid JSON' => ['not-json'],
    'JSON null' => ['null'],
    'missing collection' => [['unexpected' => []]],
    'object collection' => ['{"data":{}}'],
    'non-object row' => [['data' => ['bad']]],
    'missing ID' => [['data' => [['name' => 'no identifier']]]],
    'numeric ID' => [['data' => [['id' => 123]]]],
    'blank ID' => [['data' => [['id' => '   ']]]],
    'control character ID' => [['data' => [['id' => "model\nnext"]]]],
    'invalid creation date type' => [['data' => [['id' => 'a', 'created' => []]]]],
    'duplicate ID' => [['data' => [['id' => 'a'], ['id' => 'a']]]],
    'HTTP 200 error envelope is not an empty success' => [['data' => [], 'error' => ['message' => 'private provider detail']]],
]);

it('rejects invalid optional display names', function (): void {
    Http::fakeSequence()->push(['data' => [['id' => 'a', 'name' => ['not-string']]]]);

    expect(fn (): array => Prism::provider(Provider::Mistral)->listModels())->toThrow(ModelListingException::class);
});

it('never returns earlier pages after a later request fails', function (): void {
    Http::fakeSequence()
        ->push(['data' => [['id' => 'first']], 'has_more' => true, 'last_id' => 'first'])
        ->push(['error' => ['message' => 'private']], 500);

    expect(fn (): array => Prism::provider(Provider::Anthropic)->listModels())->toThrow(ModelListingException::class, 'request failed');
    Http::assertSentCount(2);
});

it('rejects broken Anthropic pagination', function (array $payload): void {
    Http::fakeSequence()->push($payload);

    expect(fn (): array => Prism::provider(Provider::Anthropic)->listModels())->toThrow(ModelListingException::class);
    Http::assertSentCount(1);
})->with([
    'missing has_more' => [['data' => []]],
    'has_more string' => [['data' => [['id' => 'a']], 'has_more' => 'false']],
    'missing cursor' => [['data' => [['id' => 'a']], 'has_more' => true]],
    'cursor does not match last row' => [['data' => [['id' => 'a']], 'has_more' => true, 'last_id' => 'wrong']],
    'empty page cannot advance' => [['data' => [], 'has_more' => true, 'last_id' => 'next']],
]);

it('stops duplicate Anthropic pages', function (): void {
    $page = ['data' => [['id' => 'a']], 'has_more' => true, 'last_id' => 'a'];
    Http::fakeSequence()->push($page)->push($page);

    expect(fn (): array => Prism::provider(Provider::Anthropic)->listModels())->toThrow(ModelListingException::class);
    Http::assertSentCount(2);
});

it('refuses Replicate next URLs which could redirect credentials or change the request', function (mixed $next): void {
    Http::fakeSequence()->push(['results' => [['owner' => 'author', 'name' => 'one']], 'next' => $next]);

    expect(fn (): array => Prism::provider(Provider::Replicate)->listModels())->toThrow(ModelListingException::class);
    Http::assertSentCount(1);
})->with([
    'foreign host' => ['https://attacker.test/v1/models?cursor=a'],
    'downgrade' => ['http://fixture.test/v1/models?cursor=a'],
    'foreign path' => ['https://fixture.test/v1/private?cursor=a'],
    'foreign port' => ['https://fixture.test:444/v1/models?cursor=a'],
    'userinfo' => ['https://user:pass@fixture.test/v1/models?cursor=a'],
    'fragment' => ['https://fixture.test/v1/models?cursor=a#fragment'],
    'extra query' => ['https://fixture.test/v1/models?cursor=a&api_key=other'],
    'duplicate cursor' => ['https://fixture.test/v1/models?cursor=a&cursor=b'],
    'empty cursor' => ['https://fixture.test/v1/models?cursor='],
    'no cursor' => ['https://fixture.test/v1/models'],
    'non-string URL' => [false],
]);

it('rejects Qwen success envelopes with missing or inconsistent totals', function (array $payload): void {
    Http::fakeSequence()->push($payload);

    expect(fn (): array => Prism::provider(Provider::Qwen)->listModels())->toThrow(ModelListingException::class);
    Http::assertSentCount(1);
})->with([
    'failed body' => [['success' => false, 'output' => ['models' => [], 'total' => 0, 'page_no' => 1]]],
    'missing total' => [['success' => true, 'output' => ['models' => [], 'page_no' => 1]]],
    'total below actual rows' => [['success' => true, 'output' => ['models' => [['model' => 'a']], 'total' => 0, 'page_no' => 1]]],
    'wrong page' => [['success' => true, 'output' => ['models' => [['model' => 'a']], 'total' => 2, 'page_no' => 2]]],
    'empty page with more results' => [['success' => true, 'output' => ['models' => [], 'total' => 2, 'page_no' => 1]]],
]);

it('refuses repeated Gemini cursors even when each page has a different model', function (): void {
    Http::fakeSequence()
        ->push(['models' => [['name' => 'models/a']], 'nextPageToken' => 'repeated'])
        ->push(['models' => [['name' => 'models/b']], 'nextPageToken' => 'repeated']);

    expect(fn (): array => Prism::provider(Provider::Gemini)->listModels())->toThrow(ModelListingException::class);
    Http::assertSentCount(2);
});

it('refuses missing Gemini resource prefixes and invalid continuation tokens', function (array $payload): void {
    Http::fakeSequence()->push($payload);

    expect(fn (): array => Prism::provider(Provider::Gemini)->listModels())->toThrow(ModelListingException::class);
    Http::assertSentCount(1);
})->with([
    'wrong namespace' => [['models' => [['name' => 'other/a']]]],
    'empty model ID' => [['models' => [['name' => 'models/']]]],
    'invalid token type' => [['models' => [['name' => 'models/a']], 'nextPageToken' => []]],
    'empty page with more results' => [['models' => [], 'nextPageToken' => 'more']],
]);

it('fails at the page limit without returning a truncated success', function (): void {
    $page = 0;
    Http::fake(function () use (&$page) {
        $id = 'model-'.++$page;

        return Http::response(['data' => [['id' => $id]], 'has_more' => true, 'last_id' => $id]);
    });

    expect(fn (): array => Prism::provider(Provider::Anthropic)->listModels())->toThrow(ModelListingException::class, 'invalid or incomplete');
    Http::assertSentCount(1000);
});
