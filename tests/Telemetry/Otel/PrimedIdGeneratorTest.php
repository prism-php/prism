<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\Otel\PrimedIdGenerator;

describe('PrimedIdGenerator', function (): void {
    it('returns primed span ID', function (): void {
        $generator = new PrimedIdGenerator;
        $spanId = bin2hex(random_bytes(8));

        $generator->primeSpanId($spanId);

        expect($generator->generateSpanId())->toBe($spanId);
    });

    it('returns primed trace ID', function (): void {
        $generator = new PrimedIdGenerator;
        $traceId = bin2hex(random_bytes(16));

        $generator->primeTraceId($traceId);

        expect($generator->generateTraceId())->toBe($traceId);
    });

    it('returns multiple primed IDs in FIFO order', function (): void {
        $generator = new PrimedIdGenerator;
        $spanId1 = bin2hex(random_bytes(8));
        $spanId2 = bin2hex(random_bytes(8));
        $spanId3 = bin2hex(random_bytes(8));

        $generator->primeSpanId($spanId1);
        $generator->primeSpanId($spanId2);
        $generator->primeSpanId($spanId3);

        expect($generator->generateSpanId())->toBe($spanId1)
            ->and($generator->generateSpanId())->toBe($spanId2)
            ->and($generator->generateSpanId())->toBe($spanId3);
    });

    it('throws when span ID not primed', function (): void {
        $generator = new PrimedIdGenerator;

        expect(fn (): string => $generator->generateSpanId())
            ->toThrow(RuntimeException::class, 'spanId not primed');
    });

    it('throws when trace ID not primed', function (): void {
        $generator = new PrimedIdGenerator;

        expect(fn (): string => $generator->generateTraceId())
            ->toThrow(RuntimeException::class, 'traceId not primed');
    });

    it('throws after primed IDs exhausted', function (): void {
        $generator = new PrimedIdGenerator;
        $spanId = bin2hex(random_bytes(8));

        $generator->primeSpanId($spanId);
        $generator->generateSpanId(); // Consumes the primed ID

        expect(fn (): string => $generator->generateSpanId())
            ->toThrow(RuntimeException::class);
    });
});
