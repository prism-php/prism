<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Prism\Prism\Providers\Anthropic\Maps\MessageMap;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/*
|--------------------------------------------------------------------------
| prism#59: mark a message for ONE request without marking it forever
|--------------------------------------------------------------------------
|
| `withProviderOptions()` replaces the options AND mutates the object it is
| called on. A consumer rebuilding a request every step from the same message
| list set a cache breakpoint on the last message each time -- and because the
| mark stayed on that object, every earlier "last message" kept its mark too.
| The breakpoints piled up past Anthropic's limit of four. Their workaround was
| to clone every message by hand before marking it.
|
| They should not need a workaround. `copyWithProviderOptions()` returns a
| marked COPY, leaves the original untouched, and MERGES with the options the
| message already carries rather than dropping them.
|
*/

/** How many cache breakpoints Anthropic would actually receive. */
function breakpointsIn(array $messages): int
{
    $count = 0;
    $mapped = MessageMap::map($messages);

    array_walk_recursive(
        $mapped,
        function (mixed $value, string|int $key) use (&$count): void {
            if ($key === 'type' && $value === 'ephemeral') {
                $count++;
            }
        },
    );

    return $count;
}

it('keeps exactly one breakpoint when the last message is marked on every rebuild', function (): void {
    // The consumer's loop: five steps, the request rebuilt from a growing
    // conversation each time, the newest message marked for caching.
    $conversation = [];

    for ($step = 1; $step <= 5; $step++) {
        $conversation[] = new UserMessage("step {$step}");

        $last = array_pop($conversation);
        $request = [...$conversation, $last->copyWithProviderOptions(['cacheType' => 'ephemeral'])];
        $conversation[] = $last;

        expect(breakpointsIn($request))->toBe(1);
    }
});

it('is the trap the old call sets, which is why this exists', function (): void {
    // The control. With the mutating setter the same loop leaves every earlier
    // last-message still marked, and by step five Anthropic would receive five
    // breakpoints -- past its limit of four, which is a hard request error. If
    // this ever stops accumulating, the test above proves nothing.
    $conversation = [];

    for ($step = 1; $step <= 5; $step++) {
        $conversation[] = new UserMessage("step {$step}");
        end($conversation)->withProviderOptions(['cacheType' => 'ephemeral']);
    }

    expect(breakpointsIn($conversation))->toBe(5);
});

it('leaves the original untouched', function (): void {
    $original = new UserMessage('hello');

    $copy = $original->copyWithProviderOptions(['cacheType' => 'ephemeral']);

    expect($copy)->not->toBe($original)
        ->and($original->providerOptions())->toBe([])
        ->and($copy->providerOptions('cacheType'))->toBe('ephemeral');
});

it('merges with the options the message already carries instead of dropping them', function (): void {
    // `withProviderOptions()` also REPLACES, so marking a message that already
    // carried an option silently removed it. A copy that did the same would
    // trade one trap for another.
    $original = (new AssistantMessage('reply'))->withProviderOptions(['citations' => true]);

    $copy = $original->copyWithProviderOptions(['cacheType' => 'ephemeral', 'cacheTtl' => '1h']);

    expect($copy->providerOptions())->toBe([
        'citations' => true,
        'cacheType' => 'ephemeral',
        'cacheTtl' => '1h',
    ]);
});

it('lets a new value win when a key is given twice', function (): void {
    $original = (new UserMessage('hi'))->withProviderOptions(['cacheTtl' => '5m']);

    expect($original->copyWithProviderOptions(['cacheTtl' => '1h'])->providerOptions('cacheTtl'))->toBe('1h');
});

it('works for a tool, which is marked and reused every step the same way', function (): void {
    // A tool definition is sent on every step and a breakpoint on the last
    // tool is common practice, so it piles up exactly as a message does.
    $tool = (new Tool)->as('search')->for('Search the docs')->using(fn (): string => 'ok');

    $copy = $tool->copyWithProviderOptions(['cacheType' => 'ephemeral']);

    expect($tool->providerOptions())->toBe([])
        ->and($copy->providerOptions('cacheType'))->toBe('ephemeral')
        ->and($copy->name())->toBe('search');
});

it('works for media', function (): void {
    $image = Image::fromUrl('https://example.test/a.png');

    $copy = $image->copyWithProviderOptions(['detail' => 'high']);

    expect($image->providerOptions())->toBe([])
        ->and($copy->providerOptions('detail'))->toBe('high');
});
