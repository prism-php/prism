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
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Fixtures\ToolFailure\Caller;
use Tests\Fixtures\ToolFailure\Provider;

// Keep PHP diagnostics on stderr; stdout contains only the fixture's JSON report.
ini_set('display_errors', 'stderr');
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString());
    exit(1);
});
config()->set('concurrency.default', 'process');
config()->set('prism.telemetry.enabled', true);
config()->set('prism.telemetry.capture_content', false);
if (! Concurrency::driver() instanceof ProcessDriver) {
    throw new RuntimeException('Fixture must use actual process concurrency.');
}
$events = [];
$scenario = $argv[2];
Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$events, $scenario): void {
    $events[] = ['failed' => $event->failed ?? null, 'failure' => $event->failure ?? null, 'call' => $event->toolCall, 'result' => $event->toolResult,
        'id' => $event->toolCallId, 'index' => $event->context->toolIndex, 'pid' => getmypid()];
    if ($scenario === 'two-listener') {
        throw new LogicException('synthetic-listener-outage');
    }
});
$context = Telemetry::start(TelemetryOperation::Text, 'openai', 'fixture');
[$tools, $calls] = Caller::batch(match ($scenario) {
    'two-listener' => 'two-failures',
    'provider' => 'mixed',
    default => $scenario,
});
$call = $calls[0];
$nativeEncodingError = null;
if ($scenario === 'invalid-utf8') {
    try {
        Concurrency::run([static fn () => throw new RuntimeException("synthetic-\xff")]);
    } catch (Throwable $encodingFailure) {
        $nativeEncodingError = ['class' => $encodingFailure::class, 'message' => $encodingFailure->getMessage()];
    }
}
$arrayTransport = null;
if ($scenario === 'array-contract') {
    $call = $calls[0] = new ToolCall('call', 'probe', []);
    try {
        // Capture job data as CallsTools does; a bound-method callable serializes differently.
        Concurrency::run([static fn (): array => ['id' => $call->id, 'arguments' => $call->arguments()]]);
        $arrayTransport = ['supported' => true, 'error' => null];
    } catch (Throwable $transportFailure) {
        $arrayTransport = ['supported' => false, 'error' => ['class' => $transportFailure::class, 'message' => $transportFailure->getMessage()]];
    }
    $tools = [Caller::tool('native')];
}
$classifier = [];
$resource = fopen('php://temp', 'w+');
if (method_exists(Prism::class, 'classifyToolResultsUsing')) {
    Prism::classifyToolResultsUsing(function (ToolCall $actual, mixed $result) use (&$classifier, $call, $resource): string {
        $classifier[] = ['sameCall' => $actual === $call, 'result' => $result, 'type' => get_debug_type($result), 'pid' => getmypid(), 'resource' => is_resource($resource)];

        return 'remote_failure';
    });
}
$results = [];
$flags = [];
$error = null;
$caughtAs = null;
$provider = null;
if ($scenario === 'provider') {
    $request = new Request(model: 'fixture', messages: [new UserMessage('Use the tools')], maxSteps: 3, tools: $tools);
    $messagesBefore = serialize($request->messages());
    $handler = new Provider;
}
try {
    if ($scenario === 'provider') {
        $handler->handle($request);
    } else {
        $stream = (new Caller)->stream($tools, $calls, $results);
        $flags = array_map(fn ($event): bool => $event->success, $stream);
    }
} catch (Throwable $exception) {
    $error = ['class' => $exception::class, 'message' => $exception->getMessage()];
    $caughtAs = $exception instanceof RuntimeException ? RuntimeException::class : ($exception instanceof Error ? Error::class : Throwable::class);
} finally {
    if (method_exists(Prism::class, 'classifyToolResultsUsing')) {
        Prism::classifyToolResultsUsing(null);
    }
    Telemetry::end($context);
}
if ($scenario === 'provider') {
    $provider = ['steps' => $handler->recordedSteps(), 'messagesUnchanged' => serialize($request->messages()) === $messagesBefore,
        'requests' => $handler->requests, 'aborted' => $error !== null];
}
echo json_encode(['error' => $error, 'events' => $events, 'results' => array_map(fn ($result) => $result->toArray(), $results),
    'flags' => $flags, 'classifier' => $classifier, 'pid' => getmypid(), 'caughtAs' => $caughtAs, 'provider' => $provider, 'arrayTransport' => $arrayTransport,
    'nativeEncodingError' => $nativeEncodingError], JSON_THROW_ON_ERROR).PHP_EOL;
