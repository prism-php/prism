<?php

declare(strict_types=1);

namespace Tests\ValueObjects\Media;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismUrlRefused;
use Prism\Prism\Support\HostResolver;
use Prism\Prism\ValueObjects\Media\Image;

/**
 * The cross-language `media-fetch-guard` corpus from `prism-parity`.
 *
 * This package is the REFERENCE, so this file's job is narrower than the
 * ports' equivalents will be: it is not proving the guard is right — the
 * hostile cases beside it do that — it is proving the CORPUS has not drifted
 * from the code it was recorded against, so that when a port later says "I
 * match the reference", the thing it matched is still what the reference
 * produces.
 *
 * PHP and both ports record their refusal codes. Python explicitly skips the
 * legacy unguarded-path control because that method never existed there.
 */
function fetchGuardCorpus(): array
{
    $cases = json_decode(
        (string) file_get_contents(__DIR__.'/../../Fixtures/media-fetch-guard.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    )['cases'];

    foreach ($cases as $case) {
        if (array_key_exists('redirects_always', $case) && ! is_bool($case['redirects_always'])) {
            throw new \InvalidArgumentException('redirects_always must be a boolean.');
        }
        if (($case['redirects_always'] ?? false) === true &&
            (! is_string($case['redirects_to'] ?? null) || $case['redirects_to'] === '' ||
                ($case['guarded'] ?? true) !== true)) {
            throw new \InvalidArgumentException('Repeated redirects require redirects_to and the guarded path.');
        }
    }

    return $cases;
}

/** A one-pixel PNG, so a successful fetch has a real mime type to report. */
function guardCorpusImageBytes(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
}

function guardCorpusRefusal(array $case): ?string
{
    app()->instance(HostResolver::class, new class($case['resolves'] ?? []) implements HostResolver
    {
        public function __construct(private array $map) {}

        public function resolve(string $host): array
        {
            return $this->map[$host] ?? [];
        }
    });

    // A FRESH factory per row. `Http::fake()` MERGES stubs rather than
    // replacing them, and first match wins -- so a `'*'` stub registered by an
    // earlier row outranked the redirect stub here and the redirect row
    // quietly stopped redirecting. It still reported the right answer
    // row-by-row and the wrong one in sequence, which is exactly the kind of
    // order-dependent runner that makes a corpus lie.
    Http::swap(new Factory);

    $fakes = ['*' => Http::response(guardCorpusImageBytes())];

    if (($case['redirects_always'] ?? false) === true) {
        $fakes = ['*' => Http::response('', 302, ['Location' => $case['redirects_to']])];
    } elseif (isset($case['redirects_to'])) {
        $fakes = [
            $case['redirects_to'].'*' => Http::response(guardCorpusImageBytes()),
            '*' => Http::response('', 302, ['Location' => $case['redirects_to']]),
        ];
    }

    Http::fake($fakes);

    try {
        $image = Image::fromUrl($case['url']);

        // `guarded: false` rows exercise the UNGUARDED fetch on purpose: they
        // are what would redden if a language quietly started guarding it.
        ($case['guarded'] ?? true) ? $image->fetchPublicUrlContent() : $image->fetchUrlContent();

        return null;
    } catch (PrismUrlRefused $refused) {
        if (($case['redirects_always'] ?? false) === true) {
            // Six public requests pin the default allowance to five redirect hops.
            Http::assertSentCount(6);
            foreach (Http::recorded() as [$request]) {
                expect($request->url())->toBe($case['url']);
            }
            expect($image->hasRawContent())->toBeFalse();
        }

        return $refused->code();
    }
}

it('produces what the corpus records for php', function (array $case): void {
    expect(guardCorpusRefusal($case))->toBe($case['refusal']['php']);
})->with(fn (): array => array_map(fn (array $case): array => [$case], fetchGuardCorpus()));

it('refuses something, so the corpus is not agreeing about nothing', function (): void {
    // A corpus compares columns, and a guard that refused NOTHING would make
    // every "php": null row pass. This asserts the suite actually contains
    // refusals and that the reference really produces them.
    $refusals = array_filter(array_map(guardCorpusRefusal(...), fetchGuardCorpus()));

    expect(count($refusals))->toBeGreaterThanOrEqual(8)
        ->and(array_values(array_unique($refusals)))->toEqualCanonicalizing([
            'private_address_refused',
            'redirect_refused',
            'scheme_not_allowed',
            'host_did_not_resolve',
            'too_many_redirects',
        ]);
});

it('still has the two controls that stop this suite passing vacuously', function (): void {
    $controls = array_values(array_filter(
        fetchGuardCorpus(),
        fn (array $case): bool => $case['refusal']['php'] === null,
    ));

    // One public URL that must FETCH, and one row proving the unguarded fetch
    // is still unguarded. Lose either and a guard that refuses everything, or
    // one applied to the wrong method, would score full marks here.
    expect($controls)->toHaveCount(2)
        ->and(array_column($controls, 'guarded'))->toContain(false);
});
