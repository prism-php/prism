<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Anthropic\Handlers\Structured;
use Prism\Prism\Providers\Anthropic\Handlers\Text;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', 'fake-key');
});

dataset('anthropic thinking options', [
    'adaptive' => [['type' => 'adaptive'], ['type' => 'adaptive']],
    'adaptive with display' => [['type' => 'adaptive', 'display' => 'summarized'], ['type' => 'adaptive', 'display' => 'summarized']],
    'adaptive with block binding' => [
        ['type' => 'adaptive', 'block_binding' => ['prefix_mismatch_behavior' => 'error']],
        ['type' => 'adaptive', 'block_binding' => ['prefix_mismatch_behavior' => 'error']],
    ],
    'adaptive ignores the Prism budget alias' => [['type' => 'adaptive', 'budgetTokens' => 2048], ['type' => 'adaptive']],
    'disabled' => [['type' => 'disabled'], ['type' => 'disabled']],
    'between tools' => [['type' => 'between_tools'], ['type' => 'between_tools']],
    'enabled with the Prism budget alias' => [['type' => 'enabled', 'budgetTokens' => 2048], ['type' => 'enabled', 'budget_tokens' => 2048]],
    'enabled with the API budget field' => [['type' => 'enabled', 'budget_tokens' => 4096, 'display' => 'omitted'], ['type' => 'enabled', 'budget_tokens' => 4096, 'display' => 'omitted']],
    'enabled with the default budget' => [['type' => 'enabled'], ['type' => 'enabled', 'budget_tokens' => 1024]],
    'type wins over the legacy flag' => [['enabled' => true, 'type' => 'adaptive'], ['type' => 'adaptive']],
    'legacy flag' => [['enabled' => true], ['type' => 'enabled', 'budget_tokens' => 1024]],
    'legacy flag with budget' => [['enabled' => true, 'budgetTokens' => 3000], ['type' => 'enabled', 'budget_tokens' => 3000]],
]);

it('sends the thinking configuration for text requests', function (array $thinking, array $expected): void {
    $request = Prism::text()
        ->using('anthropic', 'claude-sonnet-5-5')
        ->withPrompt('Hello')
        ->withProviderOptions(['thinking' => $thinking]);

    expect(Text::buildHttpRequestPayload($request->toRequest())['thinking'])->toBe($expected);
})->with('anthropic thinking options');

it('sends the thinking configuration for structured requests', function (array $thinking, array $expected): void {
    $request = Prism::structured()
        ->using('anthropic', 'claude-sonnet-5-5')
        ->withSchema(new ObjectSchema('result', 'result', [new StringSchema('note', 'note')], ['note']))
        ->withPrompt('Hello')
        ->withProviderOptions(['thinking' => $thinking]);

    expect(Structured::buildHttpRequestPayload($request->toRequest())['thinking'])->toBe($expected);
})->with('anthropic thinking options');

it('omits thinking when no thinking option is set or the legacy flag is off', function (array $options): void {
    $request = Prism::text()
        ->using('anthropic', 'claude-sonnet-5-5')
        ->withPrompt('Hello')
        ->withProviderOptions($options);

    expect(Text::buildHttpRequestPayload($request->toRequest()))->not->toHaveKey('thinking');
})->with([
    'none' => [[]],
    'legacy off' => [['thinking' => ['enabled' => false]]],
]);

it('streams the passthrough thinking configuration', function (): void {
    FixtureResponse::fakeStreamResponses('v1/messages', 'anthropic/stream-basic-text');

    iterator_to_array(Prism::text()
        ->using('anthropic', 'claude-sonnet-5-5')
        ->withPrompt('Hello')
        ->withProviderOptions(['thinking' => ['type' => 'between_tools'], 'effort' => 'high'])
        ->asStream(), false);

    Http::assertSent(fn (Request $request): bool => $request->data()['thinking'] === ['type' => 'between_tools']
        && $request->data()['output_config'] === ['effort' => 'high']);
});
