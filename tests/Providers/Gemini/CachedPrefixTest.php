<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\CacheStability;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/*
|--------------------------------------------------------------------------
| prism#29: the create-and-reference model
|--------------------------------------------------------------------------
|
| Gemini and Vertex cache by creating a `cachedContents` RESOURCE ahead of
| time and referencing it by name. A caller who declares what is stable has
| already said which part of their request that resource should hold -- so the
| two-step they otherwise orchestrate by hand can be derived, keyed by a digest
| of the prefix so the same prefix reuses the same resource.
|
| OPT-IN, because creating the resource is a network call that bills. A hint on
| its own must never make a request the caller did not ask for.
|
*/

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', 'test-key');
});

function geminiGenerationResponse(): array
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

function hintedMessages(): array
{
    return [
        (new UserMessage('The manual, which never changes'))->withCacheHint(CacheStability::Stable, ttl: '3600'),
        (new AssistantMessage('Understood.'))->withCacheHint(CacheStability::Stable),
        (new UserMessage("Today's question"))->withCacheHint(CacheStability::Volatile),
    ];
}

it('creates the cached resource from the stable prefix and references it', function (): void {
    Http::fake([
        '*cachedContents*' => Http::response([
            'name' => 'cachedContents/abc123',
            'model' => 'models/gemini-2.0-flash',
            'usageMetadata' => ['totalTokenCount' => 88759],
            'expireTime' => '2026-03-01T11:24:58.504522Z',
        ]),
        '*' => Http::response(geminiGenerationResponse()),
    ]);

    Prism::text()
        ->using(Provider::Gemini, 'gemini-2.0-flash')
        ->withMessages(hintedMessages())
        ->withProviderOptions(['cacheStablePrefix' => true])
        ->asText();

    // The resource holds the STABLE prefix and nothing else -- and is created
    // at the RIGHT path. `cachedContents` is a sibling of `/models`, not a
    // child of it, and a fake matching "*cachedContents*" is happy with either,
    // so the exact URL is pinned here or the mistake passes its own test.
    Http::assertSent(function ($request): bool {
        if ($request->url() !== 'https://generativelanguage.googleapis.com/v1beta/cachedContents') {
            return false;
        }

        $body = json_encode($request->data(), JSON_UNESCAPED_SLASHES) ?: '';

        return str_contains($body, 'The manual, which never changes')
            && str_contains($body, 'Understood.')
            && ! str_contains($body, "Today's question");
    });

    // And the generation references it by name, sending only what changed.
    Http::assertSent(function ($request): bool {
        if (str_contains((string) $request->url(), 'cachedContents')) {
            return false;
        }

        $body = json_encode($request->data(), JSON_UNESCAPED_SLASHES) ?: '';

        return str_contains($body, 'cachedContents/abc123')
            && str_contains($body, "Today's question")
            && ! str_contains($body, 'The manual, which never changes');
    });
});

it('reuses the resource for the same prefix instead of creating a second one', function (): void {
    // Keyed by a digest of the prefix, so a conversation whose stable half is
    // unchanged pays for the resource once rather than once per turn -- which
    // would be worse than not caching at all.
    Http::fake([
        '*cachedContents*' => Http::response([
            'name' => 'cachedContents/abc123',
            'model' => 'models/gemini-2.0-flash',
            'usageMetadata' => ['totalTokenCount' => 88759],
            'expireTime' => '2026-03-01T11:24:58.504522Z',
        ]),
        '*' => Http::response(geminiGenerationResponse()),
    ]);

    foreach ([1, 2] as $ignored) {
        Prism::text()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withMessages(hintedMessages())
            ->withProviderOptions(['cacheStablePrefix' => true])
            ->asText();
    }

    $created = 0;

    foreach (Http::recorded() as [$request]) {
        if (str_contains((string) $request->url(), 'cachedContents')) {
            $created++;
        }
    }

    expect($created)->toBe(1);
});

it('creates nothing unless the caller opts in', function (): void {
    // The control that matters: a hint is a declaration, not permission to
    // make a billable request the caller never asked for.
    Http::fake(['*' => Http::response(geminiGenerationResponse())]);

    Prism::text()
        ->using(Provider::Gemini, 'gemini-2.0-flash')
        ->withMessages(hintedMessages())
        ->asText();

    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'cachedContents'));
});

it('leaves a caller who named their own cached content alone', function (): void {
    Http::fake(['*' => Http::response(geminiGenerationResponse())]);

    Prism::text()
        ->using(Provider::Gemini, 'gemini-2.0-flash')
        ->withMessages(hintedMessages())
        ->withProviderOptions([
            'cacheStablePrefix' => true,
            'cachedContentName' => 'cachedContents/mine',
        ])
        ->asText();

    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'cachedContents'));
    Http::assertSent(fn ($request): bool => str_contains(json_encode($request->data(), JSON_UNESCAPED_SLASHES) ?: '', 'cachedContents/mine'));
});

it('sends the request unchanged when nothing is declared stable', function (): void {
    Http::fake(['*' => Http::response(geminiGenerationResponse())]);

    Prism::text()
        ->using(Provider::Gemini, 'gemini-2.0-flash')
        ->withMessages([(new UserMessage('Changes every turn'))->withCacheHint(CacheStability::Volatile)])
        ->withProviderOptions(['cacheStablePrefix' => true])
        ->asText();

    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'cachedContents'));
    Http::assertSent(fn ($request): bool => str_contains(json_encode($request->data(), JSON_UNESCAPED_SLASHES) ?: '', 'Changes every turn'));
});

it('resolves the prefix for a structured generation too', function (): void {
    // Text was the first handler to do this, and a caching feature that works
    // on one of three entry points is a feature a caller cannot rely on.
    Http::fake([
        '*cachedContents*' => Http::response([
            'name' => 'cachedContents/structured',
            'model' => 'models/gemini-2.0-flash',
            'usageMetadata' => ['totalTokenCount' => 10],
            'expireTime' => '2026-03-01T11:24:58.504522Z',
        ]),
        '*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => '{"answer":"yes"}']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 3],
        ]),
    ]);

    Prism::structured()
        ->using(Provider::Gemini, 'gemini-2.0-flash')
        ->withSchema(new ObjectSchema('result', 'The result', [new StringSchema('answer', 'The answer')], ['answer']))
        ->withMessages(hintedMessages())
        ->withProviderOptions(['cacheStablePrefix' => true])
        ->asStructured();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/cachedContents');

    Http::assertSent(function ($request): bool {
        if (str_contains((string) $request->url(), 'cachedContents')) {
            return false;
        }

        $body = json_encode($request->data(), JSON_UNESCAPED_SLASHES) ?: '';

        return str_contains($body, 'cachedContents/structured')
            && ! str_contains($body, 'The manual, which never changes');
    });
});

it('resolves the prefix for a streamed generation too', function (): void {
    Http::fake([
        '*cachedContents*' => Http::response([
            'name' => 'cachedContents/streamed',
            'model' => 'models/gemini-2.0-flash',
            'usageMetadata' => ['totalTokenCount' => 10],
            'expireTime' => '2026-03-01T11:24:58.504522Z',
        ]),
        '*' => Http::response("data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"An answer.\"}],\"role\":\"model\"},\"finishReason\":\"STOP\"}],\"usageMetadata\":{\"promptTokenCount\":10,\"candidatesTokenCount\":3}}\n\n"),
    ]);

    foreach (Prism::text()
        ->using(Provider::Gemini, 'gemini-2.0-flash')
        ->withMessages(hintedMessages())
        ->withProviderOptions(['cacheStablePrefix' => true])
        ->asStream() as $ignored) {
        // Drain it: the request is not sent until the generator is consumed.
    }

    Http::assertSent(fn ($request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/cachedContents');

    Http::assertSent(function ($request): bool {
        if (str_contains((string) $request->url(), 'cachedContents')) {
            return false;
        }

        $body = json_encode($request->data(), JSON_UNESCAPED_SLASHES) ?: '';

        return str_contains($body, 'cachedContents/streamed')
            && ! str_contains($body, 'The manual, which never changes');
    });
});
