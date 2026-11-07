<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('applies application boot error policy in actual process children and exposes the runtime-only limit', function (string $policy): void {
    $root = realpath(__DIR__.'/../..');
    $directory = $root.'/build/app-tool-error-process/'.bin2hex(random_bytes(12));
    foreach (['bootstrap/cache', 'config', 'storage/logs', 'storage/framework/views'] as $child) {
        mkdir($directory.'/'.$child, 0777, true);
    }
    $autoload = var_export($root.'/vendor/autoload.php', true);
    file_put_contents($directory.'/bootstrap/providers.php', '<?php return [];');
    file_put_contents($directory.'/bootstrap/app.php', '<?php require '.$autoload.'; return Illuminate\\Foundation\\Application::configure(basePath:dirname(__DIR__))->withExceptions()->withProviders([Prism\\Prism\\PrismServiceProvider::class, Tests\\Fixtures\\AppToolError\\BootPolicy::class])->create();');
    file_put_contents($directory.'/config/probe.php', '<?php return ["policy" => '.var_export($policy, true).'];');
    file_put_contents($directory.'/artisan', '<?php ini_set("display_errors", "stderr"); $app=require __DIR__."/bootstrap/app.php"; exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));');
    try {
        $process = new Process([PHP_BINARY, $root.'/tests/Fixtures/AppToolError/probe.php', $directory], $directory, timeout: 60);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($report['parent'])->not->toBe($report['child'])->and($report['results'])->toHaveCount(1)
            ->and($report['stream'])->toHaveCount(1)->and($report['events'])->toBe([
                ['failed' => true, 'failure' => 'tool_error', 'call' => null, 'result' => null, 'pid' => $report['parent']],
            ])->and($report['classifierCalls'])->toBe(0)->and($report['stream'][0]['success'])->toBeFalse();
        $result = $report['results'][0];
        if ($policy === 'valid') {
            expect($result)->toMatch('/\ASafe app failure from process [0-9]+\.\z/D')
                ->not->toBe('Safe app failure from process '.$report['parent'].'.');
        } elseif ($policy === 'runtime-only') {
            // This is the documented process-local limit, not a redaction success.
            expect($result)->toBe('Tool execution error: synthetic-provider-billing-secret. This error occurred during tool execution, not due to invalid parameters.');
        } else {
            expect($result)->toBe('The tool failed.');
        }
        expect($report['stream'][0]['result'])->toBe($result)->and($report['stream'][0]['error'])->toBe($result);
        if ($policy !== 'runtime-only') {
            expect($process->getOutput())->not->toContain('synthetic-provider-billing-secret', 'synthetic-callback-secret');
        }
    } finally {
        $target = str_replace('\\', '/', realpath($directory));
        $parent = str_replace('\\', '/', realpath($root.'/build/app-tool-error-process')).'/';
        if (! str_starts_with($target, $parent) || preg_match('/\A[0-9a-f]{24}\z/D', basename($target)) !== 1) {
            throw new RuntimeException('Refusing to remove a fixture outside its expected build directory.');
        }
        (new Filesystem)->deleteDirectory($target);
    }
})->with(['valid', 'throws', 'non-string', 'runtime-only']);
