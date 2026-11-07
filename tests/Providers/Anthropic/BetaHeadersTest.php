<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', 'fake-key');
});

/**
 * @return array<int, string|null>
 */
function anthropicBetaHeaders(): array
{
    return Http::recorded()
        ->map(fn (array $pair): ?string => $pair[0]->header('anthropic-beta')[0] ?? null)
        ->values()
        ->all();
}

function anthropicBetaWeatherTool(): \Prism\Prism\Tool
{
    return Tool::as('get_weather')
        ->for('Get current weather for a city')
        ->withStringParameter('city', 'The city')
        ->using(fn (string $city): string => '20°C');
}

it('merges configured betas with request-derived betas on every step', function (): void {
    config()->set('prism.providers.anthropic.anthropic_beta', 'context-1m-2025-08-07, server-side-fallback-2026-07-01');

    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-interleaved-thinking-tools');

    Prism::text()
        ->using('anthropic', 'claude-opus-5-5')
        ->withTools([anthropicBetaWeatherTool()])
        ->withMaxSteps(3)
        ->withPrompt('Which is warmer, London or Rome?')
        ->withProviderOptions([
            'fallbacks' => 'default',
            'thinking' => [
                'type' => 'adaptive',
                'display' => 'updates',
                'block_binding' => ['prefix_mismatch_behavior' => 'error'],
            ],
        ])
        ->asText();

    $expected = 'context-1m-2025-08-07,server-side-fallback-2026-07-01,thinking-display-updates-2026-08-18,thinking-binding-controls-2026-08-01';

    expect(anthropicBetaHeaders())->toBe([$expected, $expected]);
});

it('uses the per-request provider config override as the configured betas', function (): void {
    config()->set('prism.providers.anthropic.anthropic_beta', 'global-beta');

    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-fallback-before-output');

    Prism::text()
        ->using('anthropic', 'claude-opus-5-5', ['anthropic_beta' => 'override-beta'])
        ->withPrompt('Hello')
        ->withProviderOptions(['fallbacks' => [['model' => 'claude-opus-4-8']]])
        ->asText();

    expect(anthropicBetaHeaders())->toBe(['override-beta,server-side-fallback-2026-07-01']);
});

it('leaves the configured betas untouched when the request needs none', function (): void {
    config()->set('prism.providers.anthropic.anthropic_beta', 'beta1,beta2');

    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/text-fallback-before-output');

    Prism::text()
        ->using('anthropic', 'claude-opus-5-5')
        ->withPrompt('Hello')
        ->withProviderOptions(['thinking' => ['type' => 'adaptive', 'display' => 'summarized']])
        ->asText();

    expect(anthropicBetaHeaders())->toBe(['beta1,beta2']);
});

it('sends request-derived betas on every streamed step', function (): void {
    FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-interleaved-thinking-tools');

    iterator_to_array(Prism::text()
        ->using('anthropic', 'claude-opus-5-5')
        ->withTools([anthropicBetaWeatherTool()])
        ->withMaxSteps(3)
        ->withPrompt('Which is warmer, London or Rome?')
        ->withProviderOptions(['fallbacks' => 'default'])
        ->asStream(), false);

    expect(anthropicBetaHeaders())->toBe(['server-side-fallback-2026-07-01', 'server-side-fallback-2026-07-01']);

    Http::assertSent(fn (Request $request): bool => $request->data()['fallbacks'] === 'default' && $request->data()['stream'] === true);
});

it('sends request-derived betas and fallbacks on structured requests', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/structured-thinking-tool-loop');

    Prism::structured()
        ->using('anthropic', 'claude-sonnet-5-5')
        ->withSchema(new ObjectSchema('result', 'result', [new StringSchema('note', 'note')], ['note']))
        ->withTools([Tool::as('lookup_price')->for('Price lookup')->withStringParameter('sku', 'SKU')->using(fn (string $sku): string => '1.00')])
        ->withMaxSteps(6)
        ->withPrompt('Price these SKUs')
        ->withProviderOptions(['fallbacks' => 'default', 'thinking' => ['type' => 'adaptive', 'display' => 'updates']])
        ->asStructured();

    expect(anthropicBetaHeaders())->each->toBe('server-side-fallback-2026-07-01,thinking-display-updates-2026-08-18');
    expect(anthropicBetaHeaders())->toHaveCount(5);

    Http::assertSent(fn (Request $request): bool => $request->data()['fallbacks'] === 'default');
});
