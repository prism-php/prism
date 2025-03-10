<?php

namespace Tests\Http;

use Prism\Prism\Text\PendingRequest;

test('providerMeta returns an array with all providerMeta if no valuePath is provided.', function (): void {
    $class = new PendingRequest;

    $class->withProviderMeta('openai', ['key' => 'value']);

    expect($class->providerMeta('openai'))->toBe(['key' => 'value']);
});

test('providerMeta returns a string with the exact providerMeta if valuePath is provided.', function (): void {
    $class = new PendingRequest;

    $class->withProviderMeta('openai', ['key' => 'value']);

    expect($class->providerMeta('openai', 'key'))->toBe('value');
});
