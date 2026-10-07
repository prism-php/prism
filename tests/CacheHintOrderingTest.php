<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\CacheStability;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/*
|--------------------------------------------------------------------------
| prism#29: the half that helps a caller who never touches Anthropic
|--------------------------------------------------------------------------
|
| A provider with automatic prefix caching has no breakpoints to place, so the
| stability hints put nothing on its wire. They are still worth declaring,
| because ORDER decides whether prefix caching works at all: everything from
| the first changing element onwards is uncacheable on EVERY provider, so a
| volatile message placed before a stable one throws away the cache on the
| whole prompt that follows it.
|
| That mistake has no symptom. The request succeeds, the answer is correct,
| and the only evidence is a bill. So core says so, once, where the request is
| assembled -- and says it rather than throwing, because the caller asked for
| cheaper, not for a feature, and refusing to send a valid request would be a
| worse answer than sending an expensive one.
|
*/

it('says so when a volatile message is ordered before a stable one', function (): void {
    Log::spy();

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withMessages([
            (new UserMessage("Today's question"))->withCacheHint(CacheStability::Volatile),
            (new UserMessage('The manual, which never changes'))->withCacheHint(CacheStability::Stable),
        ])
        ->toRequest();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool =>
        // Names both positions, because "check your ordering" in a request
        // with forty messages is not a finding a reader can act on.
        str_contains($message, 'position 0')
        && str_contains($message, 'position 1')
        && str_contains($message, 'cach'));
});

it('stays quiet when the stable part comes first', function (): void {
    Log::spy();

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withMessages([
            (new UserMessage('The manual'))->withCacheHint(CacheStability::Stable),
            (new AssistantMessage('Understood.'))->withCacheHint(CacheStability::Stable),
            (new UserMessage("Today's question"))->withCacheHint(CacheStability::Volatile),
        ])
        ->toRequest();

    Log::shouldNotHaveReceived('warning');
});

it('stays quiet for a caller who declares nothing', function (): void {
    // The control that matters most: this check must be invisible to every
    // application that has never heard of cache hints.
    Log::spy();

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withMessages([new UserMessage('One'), new AssistantMessage('Two')])
        ->toRequest();

    Log::shouldNotHaveReceived('warning');
});

it('still sends the request, because an expensive request is not an invalid one', function (): void {
    Log::spy();

    $request = Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withMessages([
            (new UserMessage('Volatile'))->withCacheHint(CacheStability::Volatile),
            (new UserMessage('Stable'))->withCacheHint(CacheStability::Stable),
        ])
        ->toRequest();

    expect($request->messages())->toHaveCount(2);
});

it('warns once for a conversation with several misordered pairs', function (): void {
    // One finding per request, not one per pair: the fix is the same edit, and
    // a warning repeated four times is a warning a reader filters out.
    Log::spy();

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withMessages([
            (new UserMessage('Volatile'))->withCacheHint(CacheStability::Volatile),
            (new UserMessage('Stable'))->withCacheHint(CacheStability::Stable),
            (new UserMessage('Volatile again'))->withCacheHint(CacheStability::Volatile),
            (new UserMessage('Stable again'))->withCacheHint(CacheStability::Stable),
        ])
        ->toRequest();

    Log::shouldHaveReceived('warning')->once();
});
