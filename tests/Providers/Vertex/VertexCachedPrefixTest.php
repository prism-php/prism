<?php

declare(strict_types=1);

namespace Tests\Providers\Vertex;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\CacheStability;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/*
|--------------------------------------------------------------------------
| prism#29 on Vertex
|--------------------------------------------------------------------------
|
| Vertex is the other create-and-reference provider, and until now it could
| not reference a cached resource AT ALL: its payload had no `cachedContent`
| key, so a caller who created one by hand had nowhere to put it.
|
| Its resource lives under the project and location, not beside a global
| `/models` path, so the URL is derived from this provider's own base rather
| than borrowed from Gemini's.
|
*/

beforeEach(function (): void {
    config()->set('prism.providers.vertex.project_id', 'test-project');
    config()->set('prism.providers.vertex.region', 'us-central1');
    config()->set('prism.providers.vertex.access_token', 'test-access-token');
});

function vertexGenerationResponse(): array
{
    return [
        'candidates' => [[
            'content' => ['parts' => [['text' => 'An answer.']], 'role' => 'model'],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => [
            'promptTokenCount' => 10,
            'candidatesTokenCount' => 3,
            'cachedContentTokenCount' => 8,
        ],
    ];
}

function vertexHintedMessages(): array
{
    return [
        (new UserMessage('The manual, which never changes'))->withCacheHint(CacheStability::Stable, ttl: '1h'),
        (new AssistantMessage('Understood.'))->withCacheHint(CacheStability::Stable),
        (new UserMessage("Today's question"))->withCacheHint(CacheStability::Volatile),
    ];
}

it('creates the cached resource under the project and references it', function (): void {
    Http::fake([
        '*cachedContents*' => Http::response([
            'name' => 'projects/test-project/locations/us-central1/cachedContents/123',
            'model' => 'gemini-1.5-flash',
        ]),
        '*' => Http::response(vertexGenerationResponse()),
    ]);

    Prism::text()
        ->using(Provider::Vertex, 'gemini-1.5-flash')
        ->withMessages(vertexHintedMessages())
        ->withProviderOptions(['cacheStablePrefix' => true])
        ->asText();

    // The exact URL, not a substring match: `cachedContents` hangs off the
    // LOCATION, and a relative post would land under
    // `publishers/google/models`, which does not exist. A fake matching
    // "*cachedContents*" would accept that happily.
    Http::assertSent(fn ($request): bool => $request->url() === 'https://us-central1-aiplatform.googleapis.com/v1/projects/test-project/locations/us-central1/cachedContents');

    Http::assertSent(function ($request): bool {
        if (str_contains((string) $request->url(), 'cachedContents')) {
            return false;
        }

        $body = json_encode($request->data(), JSON_UNESCAPED_SLASHES) ?: '';

        return str_contains($body, 'projects/test-project/locations/us-central1/cachedContents/123')
            && str_contains($body, "Today's question")
            && ! str_contains($body, 'The manual, which never changes');
    });
});

it('creates nothing unless the caller opts in', function (): void {
    Http::fake(['*' => Http::response(vertexGenerationResponse())]);

    Prism::text()
        ->using(Provider::Vertex, 'gemini-1.5-flash')
        ->withMessages(vertexHintedMessages())
        ->asText();

    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'cachedContents'));
});

it('lets a caller reference a resource they made themselves', function (): void {
    // Vertex could not do this at all before: there was no `cachedContent` key
    // in the payload, so a hand-made resource was unusable.
    Http::fake(['*' => Http::response(vertexGenerationResponse())]);

    Prism::text()
        ->using(Provider::Vertex, 'gemini-1.5-flash')
        ->withMessages(vertexHintedMessages())
        ->withProviderOptions(['cachedContentName' => 'projects/test-project/locations/us-central1/cachedContents/mine'])
        ->asText();

    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'cachedContents') && $request->method() === 'POST' && str_ends_with((string) $request->url(), 'cachedContents'));
    Http::assertSent(fn ($request): bool => str_contains(json_encode($request->data(), JSON_UNESCAPED_SLASHES) ?: '', 'cachedContents/mine'));
});
