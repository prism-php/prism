<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;

function strictToolProviders(): array
{
    return [
        'openai responses' => ['openai', 'responses'],
        'openai chat' => ['openai', 'chat_completions'],
        'azure' => ['azure', null],
        'deepseek' => ['deepseek', null],
        'openrouter' => ['openrouter', null],
        'qwen' => ['qwen', null],
        'requesty' => ['requesty', null],
        'anthropic' => ['anthropic', null],
    ];
}

function strictWireResponse(string $provider = ''): array
{
    $response = [
        'id' => 'synthetic', 'type' => 'message', 'model' => 'synthetic', 'status' => 'completed',
        'role' => 'assistant', 'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => 'Synthetic only.']],
        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Synthetic only.']]]],
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Synthetic only.'], 'finish_reason' => 'stop']],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ];

    if ($provider === 'qwen') {
        $response['request_id'] = 'synthetic';
        $response['output'] = ['choices' => $response['choices']];
    }

    return $response;
}

it('sends exact strict and non-strict root schemas through the real provider request path', function (string $provider, ?string $format, string $shape, bool $strict): void {
    $tool = (new Tool)->as('synthetic_search')->for('Synthetic only.')->using(fn (): string => 'Unused.');
    $nested = new ObjectSchema('profile', 'Synthetic profile.', [new StringSchema('region', 'Synthetic region.')], ['region']);
    if ($shape === 'flat') {
        $tool->withStringParameter('query', 'Synthetic query.');
    } elseif ($shape === 'nested') {
        $tool->withParameter($nested);
    }
    $tool->withProviderOptions(['strict' => $strict]);
    Http::fake(function (Request $request) use ($provider, $format, $shape, $strict, $nested) {
        $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
        $envelope = $provider === 'qwen' ? $body['parameters']['tools'][0] : $body['tools'][0];
        $flat = $provider === 'anthropic' || ($provider === 'openai' && $format === 'responses');
        $definition = $flat ? $envelope : $envelope['function'];
        $key = $provider === 'anthropic' ? 'input_schema' : 'parameters';
        expect($definition['name'])->toBe('synthetic_search');
        if ($strict) {
            expect($definition['strict'] ?? null)->toBeTrue();
            if (! $flat) {
                expect(array_key_exists('strict', $envelope))->toBeFalse();
            }
        } else {
            expect(array_key_exists('strict', $definition))->toBeFalse();
        }
        $omitted = $shape === 'empty' && ! $strict && $provider !== 'anthropic' && ! ($provider === 'openai' && $format === 'chat_completions');
        if ($omitted) {
            expect(array_key_exists($key, $definition))->toBeFalse();
        } else {
            expect($definition)->toHaveKey($key);
            $schema = $definition[$key];
            expect($schema['type'])->toBe('object')
                ->and($schema['required'])->toBe($shape === 'empty' ? [] : [$shape === 'flat' ? 'query' : 'profile']);
            $expected = $shape === 'empty' ? [] : ($shape === 'flat'
                ? ['query' => ['description' => 'Synthetic query.', 'type' => 'string']]
                : ['profile' => $nested->toArray()]);
            expect($schema['properties'])->toBe($expected);
            if ($shape === 'empty') {
                $objects = json_decode($request->body(), flags: JSON_THROW_ON_ERROR);
                $native = $flat ? $objects->tools[0] : ($provider === 'qwen' ? $objects->parameters->tools[0]->function : $objects->tools[0]->function);
                expect($native->{$key}->properties)->toBeInstanceOf(stdClass::class);
            }
            if ($strict) {
                expect($schema['additionalProperties'] ?? null)->toBeFalse();
            } else {
                expect(array_key_exists('additionalProperties', $schema))->toBeFalse();
            }
        }

        return Http::response(strictWireResponse($provider));
    })->preventStrayRequests();
    $config = ['api_key' => 'synthetic-not-a-provider-key'];
    if ($format !== null) {
        $config['api_format'] = $format;
    }
    Prism::text()->using($provider, 'synthetic', $config)->withPrompt('Synthetic only.')->withTools([$tool])->asText();
    Http::assertSentCount(1);
})->with(strictToolProviders())->with(['flat', 'nested', 'empty'])->with([true, false]);

it('rejects optional strict root parameters before sending for providers requiring all properties', function (string $provider, ?string $format): void {
    $tool = (new Tool)->as('synthetic_optional')->for('Synthetic only.')
        ->withStringParameter('query', 'Synthetic query.', required: false)
        ->withStringParameter('region', 'Synthetic region.', required: false)
        ->using(fn (): string => 'Unused.')->withProviderOptions(['strict' => true]);
    Http::fake(fn () => Http::response(strictWireResponse($provider)))->preventStrayRequests();
    $config = ['api_key' => 'synthetic-not-a-provider-key'];
    if ($format !== null) {
        $config['api_format'] = $format;
    }
    try {
        Prism::text()->using($provider, 'synthetic', $config)->withPrompt('Synthetic only.')->withTools([$tool])->asText();
        test()->fail('Strict optional parameters must be refused before HTTP.');
    } catch (PrismException $error) {
        expect($error->getMessage())->toContain('synthetic_optional', 'query', 'region', 'required', 'nullable', 'strict');
    }
    Http::assertNothingSent();
})->with(array_intersect_key(strictToolProviders(), array_flip(['openai responses', 'openai chat', 'azure', 'deepseek'])));

it('preserves optional parameters for Anthropic and routers rather than applying undocumented all-required rules', function (string $provider, ?string $format): void {
    $tool = (new Tool)->as('synthetic_optional')->for('Synthetic only.')
        ->withParameter(new StringSchema('query', 'Synthetic query.', nullable: true), required: false)
        ->using(fn (): string => 'Unused.')->withProviderOptions(['strict' => true]);
    Http::fake(function (Request $request) use ($provider) {
        $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
        $schema = $provider === 'anthropic' ? $body['tools'][0]['input_schema'] : ($provider === 'qwen' ? $body['parameters']['tools'][0]['function']['parameters'] : $body['tools'][0]['function']['parameters']);
        expect($schema['required'])->toBe([])
            ->and($schema['properties']['query']['type'])->toBe(['string', 'null']);

        return Http::response(strictWireResponse($provider));
    })->preventStrayRequests();
    Prism::text()->using($provider, 'synthetic', ['api_key' => 'synthetic-not-a-provider-key'])->withPrompt('Synthetic only.')->withTools([$tool])->asText();
    Http::assertSentCount(1);
})->with(array_intersect_key(strictToolProviders(), array_flip(['anthropic', 'openrouter', 'requesty', 'qwen'])));

it('accepts required nullable parameters and preserves optional non-strict parameters', function (string $provider, ?string $format, bool $strict): void {
    $tool = (new Tool)->as('synthetic_nullable')->for('Synthetic only.')
        ->withParameter(new StringSchema('query', 'Synthetic query.', nullable: true), required: $strict)
        ->using(fn (): string => 'Unused.')->withProviderOptions(['strict' => $strict]);
    Http::fake(function (Request $request) use ($provider, $format, $strict) {
        $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
        $definition = $provider === 'openai' && $format === 'responses' ? $body['tools'][0] : $body['tools'][0]['function'];
        expect($definition['parameters']['required'])->toBe($strict ? ['query'] : [])
            ->and($definition['parameters']['properties']['query']['type'])->toBe(['string', 'null']);

        return Http::response(strictWireResponse($provider));
    })->preventStrayRequests();
    $config = ['api_key' => 'synthetic-not-a-provider-key'];
    if ($format !== null) {
        $config['api_format'] = $format;
    }
    Prism::text()->using($provider, 'synthetic', $config)->withPrompt('Synthetic only.')->withTools([$tool])->asText();
    Http::assertSentCount(1);
})->with(array_intersect_key(strictToolProviders(), array_flip(['openai responses', 'openai chat', 'azure', 'deepseek'])))->with([true, false]);

it('does not rewrite nested caller schemas when strict is enabled', function (): void {
    $nested = new ObjectSchema('profile', 'Caller schema.', [new StringSchema('note', 'Optional nested note.')], requiredFields: [], allowAdditionalProperties: true);
    $before = $nested->toArray();
    $tool = (new Tool)->as('synthetic_nested')->for('Synthetic only.')->withParameter($nested)
        ->using(fn (): string => 'Unused.')->withProviderOptions(['strict' => true]);
    Http::fake(function (Request $request) use ($before) {
        $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
        expect($body['tools'][0]['parameters']['properties']['profile'])->toBe($before)
            ->and($body['tools'][0]['parameters']['additionalProperties'] ?? null)->toBeFalse();

        return Http::response(strictWireResponse());
    })->preventStrayRequests();
    Prism::text()->using('openai', 'synthetic', ['api_key' => 'synthetic-not-a-provider-key'])->withPrompt('Synthetic only.')->withTools([$tool])->asText();
    expect($nested->toArray())->toBe($before);
    Http::assertSentCount(1);
});
