<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Prism\Prism\Contracts\DeclaresCacheStability;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
use Prism\Prism\Support\CacheHints;

/**
 * Turns a stability declaration into Gemini's create-and-reference caching.
 *
 * Gemini and Vertex do not mark a breakpoint in place: they cache a
 * `cachedContents` RESOURCE created ahead of time and referenced by name. A
 * caller who has declared which messages are stable has already said what that
 * resource should hold, so the two-step they would otherwise orchestrate by
 * hand can be derived from the same declaration that places a breakpoint on
 * Anthropic.
 *
 * OPT-IN, and that is not timidity. Creating the resource is a network request
 * that costs money and creates something with a lifetime. A hint is a
 * statement about content; it must never be read as permission to spend. So
 * the caller passes `cacheStablePrefix` to ask for this, and a caller who only
 * hints gets exactly the request they would have got anyway.
 *
 * KEYED BY A DIGEST OF THE PREFIX, because a resource created per turn would
 * be worse than no caching at all -- paying the write every turn and reading
 * it never. The same stable prefix resolves to the same resource until it
 * expires; a changed prefix is a different key and a new resource, which is
 * correct rather than a miss to work around.
 */
trait CachesStablePrefix
{
    /**
     * @param  array<int, Message>  $messages
     * @param  array<string, mixed>  $providerOptions
     * @return array{0: array<int, Message>, 1: string|null}
     */
    protected function resolveStablePrefix(array $messages, array $providerOptions, string $model): array
    {
        $name = $providerOptions['cachedContentName'] ?? null;

        // A caller who named a resource has done this themselves, possibly
        // against a prefix we cannot see. Deriving a second one would create a
        // resource nobody reads and bill them for it.
        if (is_string($name) && $name !== '') {
            return [$messages, $name];
        }

        if (($providerOptions['cacheStablePrefix'] ?? false) === false) {
            return [$messages, null];
        }

        [$prefix, $rest] = CacheHints::split($messages);

        // Nothing declared stable, or everything is: in the second case there
        // is no volatile remainder to send, and a generation needs something
        // to answer. Both send the request exactly as it came.
        if ($prefix === [] || $rest === []) {
            return [$messages, null];
        }

        $ttl = $this->cachedPrefixTtl($prefix, $providerOptions);
        $digest = hash('sha256', $model.'|'.$ttl.'|'.json_encode((new MessageMap($prefix, []))(), JSON_THROW_ON_ERROR));

        $name = Cache::remember(
            'prism:gemini:cached-content:'.$digest,
            // Expire the key slightly BEFORE the resource, so a name is never
            // handed out for something the provider has already dropped.
            max(1, $ttl - 60),
            fn (): string => $this->createCachedContent($prefix, $model, $ttl),
        );

        return [$rest, $name === '' ? null : $name];
    }

    /**
     * Seconds, from the hint that asked for it or the provider option.
     *
     * @param  array<int, Message>  $prefix
     * @param  array<string, mixed>  $providerOptions
     */
    protected function cachedPrefixTtl(array $prefix, array $providerOptions): int
    {
        if (is_int($providerOptions['cacheStablePrefix'] ?? null)) {
            return max(60, $providerOptions['cacheStablePrefix']);
        }

        foreach ($prefix as $message) {
            if ($message instanceof DeclaresCacheStability && is_string($message->cacheTtl()) && $message->cacheTtl() !== '') {
                return CacheHints::ttlToSeconds($message->cacheTtl());
            }
        }

        return 3600;
    }

    /** Where `cachedContents` lives: beside `/models`, not under it. */
    protected function cachedContentsUrl(): string
    {
        $base = rtrim((string) config('prism.providers.gemini.url', 'https://generativelanguage.googleapis.com/v1beta/models'), '/');

        return preg_replace('#/models$#', '', $base).'/cachedContents';
    }

    /**
     * @param  array<int, Message>  $prefix
     */
    protected function createCachedContent(array $prefix, string $model, int $ttl): string
    {
        // ABSOLUTE, and deliberately not relative to this client. The client's
        // base URL ends in `/models`, because every generation path does --
        // `{model}:generateContent`. `cachedContents` is its SIBLING, so a
        // relative post lands on `/v1beta/models/cachedContents`, which does
        // not exist. It answers 404 rather than failing loudly, and the fake in
        // a test matches a URL containing "cachedContents" either way, so this
        // is a mistake that passes its own tests. The test now pins the path.
        /** @var Response $response */
        $response = $this->client->post($this->cachedContentsUrl(), Arr::whereNotNull([
            'model' => str_starts_with($model, 'models/') ? $model : 'models/'.$model,
            ...(new MessageMap($prefix, []))(),
            'ttl' => $ttl.'s',
        ]));

        $name = data_get($response->json(), 'name');

        return is_string($name) ? $name : '';
    }
}
