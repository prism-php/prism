<?php

use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;
use Tests\Support\CapabilityProbe;

class WorkingDocumentationProvider
{
    public function text(): void
    {
        Http::post('https://factcheck.invalid/text', ['input' => 'fixture']);
    }
}

it('recognizes a working inherited implementation', function (): void {
    $child = new class extends WorkingDocumentationProvider {};

    expect(CapabilityProbe::implemented($child->text(...), 'Fixture', 'text'))->toBeTrue();
});

it('recognizes an overriding method that deliberately refuses', function (): void {
    $provider = new class extends WorkingDocumentationProvider
    {
        public function text(): never
        {
            throw PrismException::unsupportedProviderAction('text', 'Fixture');
        }
    };

    expect(CapabilityProbe::implemented($provider->text(...), 'Fixture', 'text'))->toBeFalse();
});

it('executes lazy stream bodies before deciding support', function (): void {
    expect(CapabilityProbe::implemented(function (): Generator {
        yield 'initial delta';
        throw PrismException::unsupportedProviderAction('stream', 'Fixture');
    }, 'Fixture', 'stream'))->toBeFalse();
});

it('does not turn unrelated exceptions into unsupported capabilities', function (): void {
    CapabilityProbe::implemented(fn () => throw new LogicException('broken fixture'), 'Fixture', 'text');
})->throws(LogicException::class, 'broken fixture');

it('does not accept a no-op as a supported or unsupported capability', function (): void {
    CapabilityProbe::implemented(fn (): null => null, 'Fixture', 'text');
})->throws(LogicException::class, 'neither dispatched HTTP nor explicitly refused');

it('can run successive probes without reusing an earlier HTTP fake', function (): void {
    foreach (range(1, 2) as $attempt) {
        expect(CapabilityProbe::implemented(fn () => Http::post('https://factcheck.invalid/text'), 'Fixture', 'text'))
            ->toBeTrue();
    }
});
