<?php

namespace Tests\Http;

use Prism\Prism\Text\PendingRequest;

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
