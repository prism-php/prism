<?php

use Prism\Prism\Concerns\Withable;

it('can update readonly properties by copying the class', function (): void {
    $instance = new class
    {
        use Withable;

        public function __construct(public readonly string $foo = 'bar') {}
    };

    $newInstance = $instance->withFoo('baz');

    expect($newInstance->foo)->toBe('baz')
        ->and($newInstance::class)->toBe($instance::class)
        ->and($instance->foo)->toBe('bar');
});
