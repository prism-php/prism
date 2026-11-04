<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Fixtures\ToolFailure\ObjectParameterFailure;
use Tests\Fixtures\ToolFailure\PromotedFailure;

it('handles tool failure flags in actual child processes without transporting the host classifier', function (string $scenario): void {
    $root = realpath(__DIR__.'/../..');
    $directory = $root.'/build/tool-failure-process/'.bin2hex(random_bytes(12));
    foreach (['bootstrap/cache', 'storage/logs', 'storage/framework/views'] as $child) {
        mkdir($directory.'/'.$child, 0777, true);
    }
    $autoload = var_export($root.'/vendor/autoload.php', true);
    file_put_contents($directory.'/bootstrap/providers.php', '<?php return [];');
    file_put_contents($directory.'/bootstrap/app.php', '<?php require '.$autoload.'; return Illuminate\\Foundation\\Application::configure(basePath:dirname(__DIR__))->withExceptions()->withProviders([Prism\\Prism\\PrismServiceProvider::class])->create();');
    file_put_contents($directory.'/artisan', '<?php ini_set("display_errors", "stderr"); $app=require __DIR__."/bootstrap/app.php"; exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));');
    try {
        $process = new Process([PHP_BINARY, $root.'/tests/Fixtures/ToolFailure/probe.php', $directory, $scenario], $directory, timeout: 60);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if ($scenario === 'array-contract') {
            expect($report['arrayTransport'])->toBe(['supported' => true, 'error' => null]);
        }
        $batch = in_array($scenario, ['two-failures', 'two-listener', 'mixed', 'provider'], true);
        expect($report['events'])->toHaveCount($batch ? 2 : 1, json_encode($report));
        foreach ($report['events'] as $index => $event) {
            expect($event['call'])->toBeNull()->and($event['result'])->toBeNull()->and($event['failed'])->toBeTrue()
                ->and($event['pid'])->toBe($report['pid'])->and($event['index'])->toBe($index);
        }
        if ($scenario === 'completed') {
            expect($report['error'])->toBeNull()->and($report['flags'])->toBe([false])
                ->and($report['events'][0]['failure'])->toBe('remote_failure')
                ->and($report['classifier'])->toBe([['sameCall' => true, 'result' => '{"success":false,"error":"synthetic customer"}', 'type' => 'string', 'pid' => $report['pid'], 'resource' => true]]);
        } elseif ($scenario === 'caught') {
            expect($report['error'])->toBeNull()->and($report['flags'])->toBe([false])
                ->and($report['events'][0]['failure'])->toBe('exception:PrismException')
                ->and($report['classifier'])->toBe([]);
        } else {
            $expected = match ($scenario) {
                'raw-error' => [Error::class, 'synthetic-native-secret'],
                'promoted' => [PromotedFailure::class, 'synthetic-native-secret:0'],
                'object-parameter' => [ObjectParameterFailure::class, '{"nested":[]}'],
                'invalid-utf8' => [RuntimeException::class, 'The concurrent tool failed because its exception could not be encoded.'],
                'unstored-parameters' => [ArgumentCountError::class, $report['error']['message']],
                'two-failures', 'two-listener' => [RuntimeException::class, 'first-native-secret'],
                'mixed', 'provider' => [RuntimeException::class, 'second-native-secret'],
                default => [RuntimeException::class, 'synthetic-native-secret'],
            };
            expect($report['error'])->toBe(['class' => $expected[0], 'message' => $expected[1]])
                ->and($report['results'])->toBe([])->and($report['flags'])->toBe([]);
            if (in_array($scenario, ['mixed', 'provider'], true)) {
                $failures = array_column($report['events'], 'failure');
                sort($failures);
                expect($failures)->toBe(['exception:RuntimeException', 'remote_failure'])
                    ->and($report['classifier'])->toHaveCount(1);
            } else {
                expect($report['classifier'])->toBe([]);
            }
            if ($scenario === 'provider') {
                expect($report['provider'])->toBe(['steps' => 0, 'messagesUnchanged' => true, 'requests' => 1, 'aborted' => true]);
            }
            if ($scenario === 'unstored-parameters') {
                expect($report['error']['message'])->toMatch('/\AToo few arguments to function Tests\\\\Fixtures\\\\ToolFailure\\\\UnstoredConstructorFailure::__construct\(\), 1 passed in .+ConcurrentToolFailure\.php on line [0-9]+ and exactly 2 expected\z/D');
            }
            if ($scenario === 'invalid-utf8') {
                // Native validation messages vary by Laravel version and may include a
                // per-worker HMAC. Prism deliberately returns a fixed private error here.
                expect($report['nativeEncodingError'])->not->toBeNull()
                    ->and($report['events'])->toHaveCount(1)
                    ->and($report['events'][0]['failure'])->toBe('exception:RuntimeException');
                $publicFailure = json_encode([$report['error'], $report['events']], JSON_THROW_ON_ERROR);
                expect($publicFailure)->not->toContain('synthetic-');
                expect($publicFailure)->not->toContain('LARAVEL-CONCURRENCY');
                expect($publicFailure)->not->toContain('HMAC');
                expect($publicFailure)->not->toMatch('/[0-9a-f]{64}/');
            }
        }
    } finally {
        $target = str_replace('\\', '/', realpath($directory));
        $parent = str_replace('\\', '/', realpath($root.'/build/tool-failure-process')).'/';
        if (! str_starts_with($target, $parent) || preg_match('/\A[0-9a-f]{24}\z/D', basename($target)) !== 1) {
            throw new RuntimeException('Refusing to remove a fixture outside its expected build directory.');
        }
        (new Filesystem)->deleteDirectory($target);
    }
})->with(['completed', 'caught', 'native', 'raw-error', 'promoted', 'object-parameter', 'unstored-parameters', 'invalid-utf8', 'two-failures', 'two-listener', 'mixed', 'provider', 'array-contract']);
