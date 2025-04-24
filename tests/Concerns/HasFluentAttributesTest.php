<?php

use Prism\Prism\Concerns\HasFluentAttributes;

it('can update readonly properties by copying the class', function (): void {
    $instance = new class
    {
        use HasFluentAttributes;

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
        use HasFluentAttributes;

        public function __construct(public readonly string $foo = 'bar') {}
    };

    $newInstance = $instance->withFoo(foo: 'baz');

    expect($newInstance->foo)->toBe('baz')
        ->and($newInstance::class)->toBe($instance::class)
        ->and($instance->foo)->toBe('bar');
});

it('disallows empty arguments', function (): void {
    $instance = new class
    {
        use HasFluentAttributes;

        public function __construct(public readonly string $foo = 'bar') {}
    };

    $instance->withFoo();
})->throws(InvalidArgumentException::class, 'Method withFoo expects exactly one argument.');

it('disallows multiple arguments', function (): void {
    $instance = new class
    {
        use HasFluentAttributes;

        public function __construct(public readonly string $foo = 'bar') {}
    };

    $instance->withFoo('baz', 'qux');
})->throws(InvalidArgumentException::class, 'Method withFoo expects exactly one argument.');

it('throws if the property does not exist', function (): void {
    $instance = new class
    {
        use HasFluentAttributes;

        public function __construct(public readonly string $foo = 'bar') {}
    };

    $instance->withBaz('baz');
})->throws(\BadMethodCallException::class, 'Method withBaz does not exist.');

it('can still call other methods', function (): void {
    $instance = new class
    {
        use HasFluentAttributes;

        public function __construct(public readonly string $foo = 'bar') {}

        public function test(string $foo): string
        {
            return 'baz';
        }
    };

    $output = $instance->test('baz');

    expect($output)->toBe('baz')
        ->and($instance->foo)->toBe('bar');
});

it('will prefer existing methods over properties', function (): void {
    $instance = new class
    {
        use HasFluentAttributes;

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
