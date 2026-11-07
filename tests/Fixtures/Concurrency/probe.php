<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Concurrency\ProcessDriver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Concurrency;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Tool;
use Tests\Fixtures\Concurrency\ProviderToolCalls;
use Tests\Fixtures\ToolFailure\Caller;

ini_set('display_errors', 'stderr');
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config()->set('concurrency.default', 'process');
if (! Concurrency::driver() instanceof ProcessDriver) {
    throw new RuntimeException('Fixture requires real process concurrency.');
}
$rows = [];
$tool = (new Tool)->as('probe')->for('Transport fixture')->withStringParameter('input', 'Input')->concurrent()
    ->using(static fn (string $input): string => json_encode(['input' => $input, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
foreach (ProviderToolCalls::cases() as $name => $case) {
    $call = $case['call'];
    $expected = ['id' => $call->id, 'name' => $call->name, 'rawArguments' => $call->arguments, 'arguments' => $call->arguments(), 'reasoningSummary' => $call->reasoningSummary];
    $transport = null;
    $transportError = null;
    try {
        $transport = Concurrency::run([static fn (): array => [
            'id' => $call->id, 'name' => $call->name, 'rawArguments' => $call->arguments,
            'arguments' => $call->arguments(), 'reasoningSummary' => $call->reasoningSummary, 'pid' => getmypid(),
        ]])[0];
    } catch (Throwable $failure) {
        $transportError = ['class' => $failure::class, 'message' => $failure->getMessage()];
    }
    $results = [];
    $execution = null;
    $executionError = null;
    try {
        $events = (new Caller)->stream([$tool], [$call], $results);
        $event = $events[0];
        $execution = ['body' => json_decode((string) $results[0]->result, true, flags: JSON_THROW_ON_ERROR),
            'id' => $results[0]->toolCallId, 'args' => $results[0]->args,
            'success' => $event instanceof ToolResultEvent && $event->success, 'resultCount' => count($results)];
    } catch (Throwable $failure) {
        $executionError = ['class' => $failure::class, 'message' => $failure->getMessage()];
    }
    $rows[$name] = ['normalizerOnly' => $case['normalizerOnly'], 'expected' => $expected,
        'transport' => $transport, 'transportError' => $transportError, 'execution' => $execution, 'executionError' => $executionError];
}
echo json_encode(['pid' => getmypid(), 'serializer' => InstalledVersions::getPrettyVersion('laravel/serializable-closure'), 'rows' => $rows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
