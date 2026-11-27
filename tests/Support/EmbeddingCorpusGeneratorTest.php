<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('preserves measured port rows and only clears agreement when the executed PHP observation changes', function (string $scenario): void {
    $root = realpath(__DIR__.'/../..');
    $directory = $root.'/build/embedding-generator/'.bin2hex(random_bytes(12));
    foreach (['dev', 'tests/Fixtures', 'vendor'] as $child) {
        mkdir($directory.'/'.$child, 0777, true);
    }
    $fixture = $directory.'/tests/Fixtures/embedding-decoding.json';
    $original = file_get_contents($root.'/tests/Fixtures/embedding-decoding.json');
    $document = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
    $input = $document;
    $changed = str_starts_with($scenario, 'changed');
    $write = str_ends_with($scenario, '-write');
    if ($changed) {
        $input['cases'][0]['expected']['php'] = ['embedding' => [999]];
    }
    $inputJson = json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    file_put_contents($fixture, $inputJson);
    copy($root.'/dev/generate-embedding-corpus.php', $directory.'/dev/generate-embedding-corpus.php');
    file_put_contents($directory.'/vendor/autoload.php', '<?php require '.var_export($root.'/vendor/autoload.php', true).';');

    try {
        $command = [PHP_BINARY, $directory.'/dev/generate-embedding-corpus.php'];
        if ($scenario === 'unknown-flag') {
            $command[] = '--unknown';
        } elseif ($write) {
            $command[] = '--write';
        }
        $process = new Process($command, $directory, timeout: 30);
        $process->run();
        if ($scenario === 'unknown-flag') {
            expect($process->getExitCode())->not->toBe(0)
                ->and(file_get_contents($fixture))->toBe($inputJson);
        } else {
            expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
            $output = $write ? file_get_contents($fixture) : $process->getOutput();
            $actual = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            $expected = $document;
            if ($changed) {
                $expected['cases'][0]['agrees'] = false;
            }
            // This compares every port observation and every other metadata field.
            expect($actual)->toBe($expected);
            if (! $changed) {
                expect($output)->toBe($original);
            }
            if ($write) {
                expect($process->getOutput())->toBe('');
            } else {
                expect(file_get_contents($fixture))->toBe($inputJson);
            }
        }
        expect(file_get_contents($root.'/tests/Fixtures/embedding-decoding.json'))->toBe($original);
    } finally {
        $target = str_replace('\\', '/', realpath($directory));
        $parent = str_replace('\\', '/', realpath($root.'/build/embedding-generator')).'/';
        if (! str_starts_with($target, $parent) || preg_match('/\A[0-9a-f]{24}\z/D', basename($target)) !== 1) {
            throw new RuntimeException('Refusing cleanup outside the embedding generator fixture directory.');
        }
        (new Filesystem)->deleteDirectory($target);
    }
})->with(['same-stdout', 'same-write', 'changed-stdout', 'changed-write', 'unknown-flag']);
