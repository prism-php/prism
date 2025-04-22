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

it('can take named arguments', function (): void {
    $instance = new class
    {
        use Withable;

        public function __construct(public readonly string $foo = 'bar') {}
    };

    $newInstance = $instance->withFoo(foo: 'baz');

    expect($newInstance->foo)->toBe('baz')
        ->and($newInstance::class)->toBe($instance::class)
        ->and($instance->foo)->toBe('bar');
});

it('throws if the property does not exist', function (): void {
    $instance = new class
    {
        use Withable;

        public function __construct(public readonly string $foo = 'bar') {}
    };

    $instance->withBaz('baz');
})->throws(\BadMethodCallException::class, 'Method withBaz does not exist.');

it('can still call other methods', function (): void {
    $instance = new class
    {
        use Withable;

        public function __construct(public readonly string $foo = 'bar') {}

        public function test(string $foo): self
        {
            return new self($foo);
        }
    };

    $newInstance = $instance->test('baz');

    expect($newInstance->foo)->toBe('baz')
        ->and($instance->foo)->toBe('bar');
});

it('will prefer existing methods over properties', function (): void {
    $instance = new class
    {
        use Withable;

        public function __construct(public readonly string $foo = 'bar') {}

        public function withFoo(string $foo): self
        {
            return new self('not baz');
        }
    };

    $newInstance = $instance->withFoo('baz');

    expect($newInstance->foo)->toBe('not baz')
        ->and($instance->foo)->toBe('bar');
});
