<?php

namespace Tests\Http;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\ValueObjects\ProviderOption;

test('providerOptions returns an array with all providerOptions if no valuePath is provided.', function (): void {
    $class = new PendingRequest;

    $class->withProviderOptions(['key' => 'value']);

    expect($class->providerOptions())->toBe(['key' => 'value']);
});

test('providerOptions returns a string with the exact providerOptions if valuePath is provided.', function (): void {
    $class = new PendingRequest;

    $class->withProviderOptions(['key' => 'value']);

    expect($class->providerOptions('key'))->toBe('value');
});

test('providerOptions returns null if the value path is not set', function (): void {
    $class = new PendingRequest;

    $class->withProviderOptions(['key' => 'value']);

    expect($class->providerOptions('foo'))->toBeNull();
});

test('providerOptions can be set using ProviderOption class', function (): void {
    $class = new PendingRequest;

    $class->withProviderOptions([new ProviderOption('key', 'value')]);

    expect($class->providerOptions('key'))->toBe('value');
});

test('providerOptions can be set using ProviderOption class and class key takes precedence', function (): void {
    $class = new PendingRequest;

    $class->withProviderOptions(['key1' => new ProviderOption('key2', 'value')]);

    expect($class->providerOptions('key2'))->toBe('value');
});

test('providerOptions can be set without key when using extended ProviderOption class', function (): void {
    $class = new PendingRequest;

    $option = new class('value') extends ProviderOption
    {
        public function __construct(string $value)
        {
            parent::__construct('reasoning', $value);
        }
    };

    $class->withProviderOptions(['key1' => $option]);

    expect($class->providerOptions('reasoning'))->toBe('value');
});

test('providerOptions wont return ProviderOption for incorrect provider', function (): void {
    $class = new PendingRequest;
    $class->using(Provider::Anthropic);

    $class->withProviderOptions([new ProviderOption(
        'reasoning',
        'value',
        Provider::OpenAI,
    )]);

    expect($class->providerOptions('reasoning'))->toBeNull();
});

test('providerOptions will always return ProviderOption when option does not specify provider', function (): void {
    $class = new PendingRequest;
    $class->using(Provider::Anthropic);

    $class->withProviderOptions([new ProviderOption(
        'reasoning',
        'value',
    )]);

    expect($class->providerOptions('reasoning'))->toBe('value');
});
