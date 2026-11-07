<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Vertex\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Prism\Prism\Contracts\DeclaresCacheStability;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
use Prism\Prism\Support\CacheHints;

/**
 * Vertex's half of create-and-reference caching.
 *
 * The rules that are ABOUT THE DECLARATION -- where the stable prefix ends,
 * what a ttl string means -- live in {@see CacheHints} and are shared with
 * every provider, so two providers cannot end up disagreeing about what
 * "stable" meant. What is genuinely Vertex's own is here: a `cachedContents`
 * resource hangs off the PROJECT and LOCATION rather than a global path, and
 * the model is named by its full publisher path.
 *
 * Opt-in for the same reason as Gemini's: creating the resource is a billable
 * request, and a hint is a statement about content, never permission to spend.
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

        if (is_string($name) && $name !== '') {
            return [$messages, $name];
        }

        if (($providerOptions['cacheStablePrefix'] ?? false) === false) {
            return [$messages, null];
        }

        [$prefix, $rest] = CacheHints::split($messages);

        if ($prefix === [] || $rest === []) {
            return [$messages, null];
        }

        $ttl = $this->cachedPrefixTtl($prefix, $providerOptions);
        $digest = hash('sha256', $model.'|'.$ttl.'|'.json_encode((new MessageMap($prefix, []))(), JSON_THROW_ON_ERROR));

        $name = Cache::remember(
            'prism:vertex:cached-content:'.$digest,
            max(1, $ttl - 60),
            fn (): string => $this->createCachedContent($prefix, $model, $ttl),
        );

        return [$rest, $name === '' ? null : $name];
    }

    /**
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

    /**
     * @param  array<int, Message>  $prefix
     */
    protected function createCachedContent(array $prefix, string $model, int $ttl): string
    {
        // ABSOLUTE. This client's base URL ends in
        // `.../locations/{region}/publishers/google/models`, because every
        // generation path does. `cachedContents` hangs off the LOCATION, two
        // segments higher, so a relative post would land somewhere that does
        // not exist -- and a test fake matching "*cachedContents*" would accept
        // that URL without complaint. The test pins the exact one.
        $base = rtrim($this->cacheBaseUrl(), '/');
        $location = (string) preg_replace('#/publishers/google/models$#', '', $base);

        /** @var Response $response */
        $response = $this->client->post($location.'/cachedContents', Arr::whereNotNull([
            'model' => $base.'/'.$model,
            ...(new MessageMap($prefix, []))(),
            'ttl' => $ttl.'s',
        ]));

        $name = data_get($response->json(), 'name');

        return is_string($name) ? $name : '';
    }

    /** The provider's base URL, which knows the project and region. */
    abstract protected function cacheBaseUrl(): string;
}
