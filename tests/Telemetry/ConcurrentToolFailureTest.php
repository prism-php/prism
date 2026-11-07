<?php

declare(strict_types=1);

use Illuminate\Concurrency\SyncDriver;
use Illuminate\Support\Facades\Concurrency;
use Prism\Prism\Support\ConcurrentToolFailure;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\ToolFailure\Caller;
use Tests\Fixtures\ToolFailure\ObjectParameterFailure;
use Tests\Fixtures\ToolFailure\PromotedFailure;

it('transports native constructor parameters with Laravel JSON normalization', function (): void {
    $original = new ObjectParameterFailure((object) ['nested' => (object) []]);
    $envelope = unserialize(serialize(ConcurrentToolFailure::capture($original, 1.0)));
    $envelope->validate();
    try {
        $envelope->rethrow();
    } catch (ObjectParameterFailure $actual) {
        expect($actual->details)->toBe(['nested' => []])->and($actual->getMessage())->toBe('{"nested":[]}');
    }
    $promoted = ConcurrentToolFailure::capture(new PromotedFailure('synthetic-secret', 0), 1.0);
    expect(fn () => $promoted->rethrow())->toThrow(PromotedFailure::class, 'synthetic-secret:0');
});

it('rejects malformed native failure envelopes before reconstructing anything', function (string $scenario): void {
    $reflection = new ReflectionClass(ConcurrentToolFailure::class);
    $envelope = $reflection->newInstanceWithoutConstructor();
    if ($scenario !== 'uninitialized') {
        $packet = ['successful' => false, 'exception' => RuntimeException::class, 'message' => 'synthetic-secret',
            'file' => 'fixture', 'line' => 1, 'parameters' => []];
        match ($scenario) {
            'wrong-class' => $packet['exception'] = stdClass::class,
            'extra-fields' => $packet['extra'] = 'invalid',
            'parameters' => $packet['parameters'] = ['not an argument name'],
            default => null,
        };
        $payload = $scenario === 'broken-json' ? '{' : json_encode($packet, JSON_THROW_ON_ERROR);
        foreach (['nativePayload' => $payload, 'failure' => $scenario === 'unsafe-kind' ? 'exception:secret body' : 'exception:RuntimeException',
            'durationMs' => $scenario === 'duration' ? INF : 1.0] as $key => $value) {
            $reflection->getProperty($key)->setValue($envelope, $value);
        }
    }
    expect(fn () => $envelope->rethrow())->toThrow(RuntimeException::class, 'Invalid concurrent tool failure envelope.');
})->with(['uninitialized', 'broken-json', 'wrong-class', 'extra-fields', 'parameters', 'unsafe-kind', 'duration']);

it('fails closed on malformed completed concurrent results', function (mixed $packet): void {
    Concurrency::shouldReceive('driver')->once()->andReturn(new SyncDriver);
    Concurrency::shouldReceive('run')->once()->andReturn([$packet]);
    $tool = (new Tool)->as('probe')->for('Probe')->concurrent()->using(fn (): string => 'must not be substituted');
    $results = [];
    expect(function () use ($tool, &$results): array {
        return (new Caller)->stream([$tool], [new ToolCall('call', 'probe', [])], $results);
    })
        ->toThrow(RuntimeException::class, 'Invalid concurrent tool result envelope.');
    expect($results)->toBe([]);
})->with(['null' => [null], 'unknown fields' => [['unexpected' => 'value']], 'invalid result' => [['toolResult' => 'synthetic-secret']]]);

it('accepts reordered driver result keys while preserving model-facing order', function (): void {
    $packet = static fn (string $id): array => ['toolResult' => new ToolResult($id, $id, [], $id), 'events' => [], 'durationMs' => 1.0, 'failed' => false, 'failure' => null];
    Concurrency::shouldReceive('driver')->once()->andReturn(new SyncDriver);
    Concurrency::shouldReceive('run')->once()->andReturn([1 => $packet('second'), 0 => $packet('first')]);
    $tools = [(new Tool)->as('first')->for('First')->concurrent(), (new Tool)->as('second')->for('Second')->concurrent()];
    $results = [];
    (new Caller)->stream($tools, [new ToolCall('first', 'first', []), new ToolCall('second', 'second', [])], $results);
    expect(array_map(fn (ToolResult $result): mixed => $result->result, $results))->toBe(['first', 'second']);
});
