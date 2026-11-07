<?php

declare(strict_types=1);

use Illuminate\Concurrency\ProcessDriver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Prism;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Fixtures\ToolFailure\Caller;

ini_set('display_errors', 'stderr');
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config()->set('concurrency.default', 'process');
config()->set('prism.telemetry.enabled', true);
config()->set('prism.telemetry.capture_content', false);
if (! Concurrency::driver() instanceof ProcessDriver) {
    throw new RuntimeException('The fixture requires actual process concurrency.');
}
$parent = getmypid();
$child = Concurrency::run([static function (): int {
    $pid = getmypid();
    if ($pid === false) {
        throw new RuntimeException('Could not read the child process identifier.');
    }

    return $pid;
}])[0];
if ($child === $parent) {
    throw new RuntimeException('The fixture did not launch a child.');
}
$events = [];
Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$events): void {
    $events[] = ['failed' => $event->failed, 'failure' => $event->failure, 'call' => $event->toolCall, 'result' => $event->toolResult, 'pid' => getmypid()];
});
$context = Telemetry::start(TelemetryOperation::Text, 'openai', 'fixture');
$classifierCalls = 0;
Prism::classifyToolResultsUsing(function () use (&$classifierCalls): string {
    $classifierCalls++;

    return 'must_not_override';
});
if (config('probe.policy') === 'runtime-only') {
    Prism::handleToolErrorsUsing(fn (): string => 'Parent-only app failure.');
}
$tool = (new Tool)->as('probe')->for('Process probe')->withStringParameter('query', 'Query')->concurrent()
    ->using(static fn (string $query) => throw new RuntimeException('synthetic-provider-billing-secret'));
$call = new ToolCall('call', 'probe', ['query' => 'public-input']);
$results = [];
$stream = (new Caller)->stream([$tool], [$call], $results);
echo json_encode(['parent' => $parent, 'child' => $child, 'results' => array_map(fn ($result): string => $result->result, $results),
    'stream' => array_map(fn ($event): array => $event->toArray(), $stream), 'events' => $events, 'classifierCalls' => $classifierCalls], JSON_THROW_ON_ERROR);
Prism::handleToolErrorsUsing(null);
Prism::classifyToolResultsUsing(null);
Telemetry::end($context);
