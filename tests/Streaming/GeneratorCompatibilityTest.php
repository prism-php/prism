<?php

declare(strict_types=1);

require_once __DIR__.'/GeneratorProbe.php';

$baseline = json_decode(file_get_contents(__DIR__.'/generator-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
dataset('published generator compatibility', array_map(fn (array $row): array => [$row], $baseline['rows']));

it('preserves the published public generator contract and cleanup order', function (array $expected): void {
    expect(g203GeneratorObservation($expected['telemetry'], $expected['scenario']))->toBe($expected);
})->with('published generator compatibility');
