<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Text\Request;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolOutput;
use Prism\Prism\ValueObjects\ToolResult;

class FailureCallsToolsHandler
{
    use CallsTools {
        callTools as public execute;
        callToolsAndYieldEvents as public stream;
        resolveToolApprovalsAndYieldEvents as public resume;
    }
}

beforeEach(function (): void {
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', false);
    Event::fake([ToolInvoked::class]);
    Telemetry::start(TelemetryOperation::Text, 'openai', 'test');
});

afterEach(function (): void {
    Prism::classifyToolResultsUsing(null);
    Telemetry::end(Telemetry::current());
});

it('reports native exception and ToolError kinds without capturing content or calling the classifier', function (bool $exception): void {
    $classifierCalls = 0;
    Prism::classifyToolResultsUsing(function () use (&$classifierCalls): string {
        $classifierCalls++;

        return 'must_not_override';
    });
    $tool = (new Tool)->as('tool')->for('Failure probe');
    if ($exception) {
        $tool->withoutErrorHandling()->using(fn () => throw new PrismException('synthetic-customer-secret'));
    } else {
        $tool->withErrorHandling(fn (): string => 'synthetic-customer-secret')->using(fn () => throw new RuntimeException('synthetic-customer-secret'));
    }
    $results = (new FailureCallsToolsHandler)->execute([$tool], [new ToolCall('call', 'tool', [])]);
    expect($results[0]->result)->toBe('synthetic-customer-secret')->and($classifierCalls)->toBe(0);
    Event::assertDispatchedTimes(ToolInvoked::class, 1);
    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => $event->failed
        && $event->failure === ($exception ? 'exception:PrismException' : 'tool_error')
        && ! $event->toolCall instanceof ToolCall && ! $event->toolResult instanceof ToolResult);
})->with([true, false]);

it('classifies completed tool results without changing their bytes or stream payload', function (bool $wrapped): void {
    $body = '{"success":false,"error":"synthetic customer data"}';
    $call = new ToolCall('call', 'tool', []);
    $seen = [];
    Prism::classifyToolResultsUsing(function (ToolCall $actual, mixed $result) use (&$seen, $call): string {
        $seen[] = [$actual === $call, $result, get_debug_type($result)];

        return 'remote.unavailable';
    });
    $tool = (new Tool)->as('tool')->for('Failure probe')->using(fn (): ToolOutput|string => $wrapped ? new ToolOutput($body) : $body);
    $results = [];
    $events = iterator_to_array((new FailureCallsToolsHandler)->stream([$tool], [$call], 'message', $results));
    expect($seen)->toBe([[true, $body, 'string']])
        ->and($results[0]->result)->toBe($body)
        ->and($events[0])->toBeInstanceOf(ToolResultEvent::class)
        ->and($events[0]->toolResult)->toBe($results[0])
        ->and($events[0]->success)->toBeFalse()
        ->and($events[0]->error)->toBeNull();
    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => $event->failed && $event->failure === 'remote.unavailable'
        && ! $event->toolCall instanceof ToolCall && ! $event->toolResult instanceof ToolResult);
})->with([true, false]);

it('keeps successful bytes and stream flags unchanged with no classifier', function (): void {
    $body = '{"success":false,"error":"data only until host classifies it"}';
    $tool = (new Tool)->as('tool')->for('Probe')->using(fn (): string => $body);
    $results = [];
    $events = iterator_to_array((new FailureCallsToolsHandler)->stream([$tool], [new ToolCall('call', 'tool', [])], 'message', $results));
    expect($results[0]->result)->toBe($body)->and($events[0]->success)->toBeTrue()->and($events[0]->error)->toBeNull();
    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => ! $event->failed && $event->failure === null
        && ! $event->toolCall instanceof ToolCall && ! $event->toolResult instanceof ToolResult);
});

it('keeps classifier failures out of model-facing results', function (bool $throws): void {
    Prism::classifyToolResultsUsing($throws ? fn () => throw new RuntimeException('synthetic-secret') : fn (): string => '{"customer":"synthetic-secret"}');
    $tool = (new Tool)->as('tool')->for('Probe')->using(fn (): string => 'unchanged result');
    $results = (new FailureCallsToolsHandler)->execute([$tool], [new ToolCall('call', 'tool', [])]);
    expect($results[0]->result)->toBe('unchanged result');
    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => $event->failed
        && $event->failure === ($throws ? 'classifier_error' : 'classified_failure')
        && ! $event->toolCall instanceof ToolCall && ! $event->toolResult instanceof ToolResult);
})->with([true, false]);

it('gives sequential and concurrent calls identical classifier inputs and flags', function (): void {
    $body = '{"success":false}';
    $call = new ToolCall('call', 'tool', ['input' => 'value']);
    $observed = [];
    Prism::classifyToolResultsUsing(function (ToolCall $actual, mixed $result) use (&$observed, $call): string {
        $observed[] = [$actual === $call, $result, get_debug_type($result)];

        return 'same.kind';
    });
    $flags = [];
    foreach ([false, true] as $concurrent) {
        $tool = (new Tool)->as('tool')->for('Probe')->withStringParameter('input', 'Input')->using(fn (string $input): ToolOutput => new ToolOutput($body))->concurrent($concurrent);
        $results = [];
        $events = iterator_to_array((new FailureCallsToolsHandler)->stream([$tool], [$call], 'message', $results));
        $event = Event::dispatched(ToolInvoked::class)->last()[0];
        $flags[] = [$event->failed, $event->failure, $events[0]->success, $results[0]->result];
    }
    expect($observed)->toBe([[true, $body, 'string'], [true, $body, 'string']])
        ->and($flags)->toBe([[true, 'same.kind', false, $body], [true, 'same.kind', false, $body]]);
});

it('classifies each concurrent tool once with an independent verdict', function (): void {
    $calls = [];
    Prism::classifyToolResultsUsing(function (ToolCall $call, mixed $result) use (&$calls): ?string {
        $calls[] = [$call->id, $result];

        return $result === 'bad' ? 'remote_failure' : null;
    });
    $tools = [
        (new Tool)->as('one')->for('One')->using(fn (): string => 'bad')->concurrent(),
        (new Tool)->as('two')->for('Two')->using(fn (): string => 'good')->concurrent(),
    ];
    $results = [];
    $events = iterator_to_array((new FailureCallsToolsHandler)->stream($tools, [new ToolCall('c1', 'one', []), new ToolCall('c2', 'two', [])], 'message', $results));
    expect($calls)->toBe([['c1', 'bad'], ['c2', 'good']])->and($events[0]->success)->toBeFalse()->and($events[1]->success)->toBeTrue();
    Event::assertDispatchedTimes(ToolInvoked::class, 2);
    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => $event->toolCallId === 'c1' && $event->failure === 'remote_failure');
    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => $event->toolCallId === 'c2' && ! $event->failed && $event->failure === null);
});

it('preserves provider request bytes and model steps when classification changes flags', function (): void {
    config()->set('prism.providers.openai.api_key', 'synthetic-api-key');
    $runs = [];
    foreach ([null, fn (): string => 'research.failed'] as $classifier) {
        Prism::classifyToolResultsUsing($classifier);
        Http::swap(new Factory);
        $sequence = Http::sequence();
        foreach ([1, 2] as $index) {
            $file = __DIR__.'/../Fixtures/openai/generate-text-with-multiple-tools-'.$index.'.json';
            expect(is_file($file))->toBeTrue();
            $sequence->push(file_get_contents($file), 200, ['Content-Type' => 'application/json']);
        }
        Http::fake(['*' => $sequence])->preventStrayRequests();
        $body = '{"success":false,"error":"synthetic customer data"}';
        $tools = [
            (new Tool)->as('weather')->for('Weather')->withStringParameter('city', 'City')->using(fn (string $city): string => $body),
            (new Tool)->as('search')->for('Search')->withStringParameter('query', 'Query')->using(fn (string $query): string => $body),
        ];
        $response = Prism::text()->using('openai', 'gpt-4o')->withTools($tools)->withMaxSteps(3)->withPrompt('Use the tools')->asText();
        $runs[] = ['requests' => Http::recorded()->map(fn (array $pair): string => $pair[0]->body())->all(),
            'steps' => count($response->steps), 'text' => $response->text,
            'results' => json_encode(array_map(fn (ToolResult $result): array => $result->toArray(), $response->steps[0]->toolResults), JSON_THROW_ON_ERROR)];
    }
    expect($runs[0]['requests'])->toHaveCount(2)->and($runs[0]['steps'])->toBe(2)->and($runs[1])->toBe($runs[0]);
});

it('classifies stream flags even when telemetry emission is disabled', function (): void {
    config()->set('prism.telemetry.enabled', false);
    Prism::classifyToolResultsUsing(fn (): string => 'service.failed');
    $tool = (new Tool)->as('tool')->for('Probe')->using(fn (): string => 'original');
    $results = [];
    $events = iterator_to_array((new FailureCallsToolsHandler)->stream([$tool], [new ToolCall('call', 'tool', [])], 'message', $results));
    expect($events[0]->success)->toBeFalse()->and($results[0]->result)->toBe('original');
    Event::assertNotDispatched(ToolInvoked::class);
    config()->set('prism.telemetry.enabled', true);
});

it('emits one safe native failure event then rethrows the same sequential exception', function (): void {
    $exception = new RuntimeException('synthetic-native-secret');
    $tool = (new Tool)->as('tool')->for('Probe')->withoutErrorHandling()->using(fn () => throw $exception);
    $classifierCalls = 0;
    Prism::classifyToolResultsUsing(function () use (&$classifierCalls): string {
        $classifierCalls++;

        return 'must_not_run';
    });
    try {
        (new FailureCallsToolsHandler)->execute([$tool], [new ToolCall('call', 'tool', [])]);
        $this->fail('Native exception was converted into a model result.');
    } catch (RuntimeException $actual) {
        expect($actual)->toBe($exception);
    }
    expect($classifierCalls)->toBe(0);
    Event::assertDispatchedTimes(ToolInvoked::class, 1);
    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => $event->failed && $event->failure === 'exception:RuntimeException'
        && ! $event->toolCall instanceof ToolCall && ! $event->toolResult instanceof ToolResult);
});

it('does not leak anonymous exception class metadata into failure kinds', function (): void {
    $exception = new class('synthetic-native-secret') extends RuntimeException {};
    $tool = (new Tool)->as('tool')->for('Probe')->withoutErrorHandling()->using(fn () => throw $exception);
    expect(fn (): array => (new FailureCallsToolsHandler)->execute([$tool], [new ToolCall('call', 'tool', [])]))->toThrow($exception::class);
    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => $event->failed && $event->failure === 'exception:unknown');
});

it('preserves the original native throwable when its telemetry listener throws', function (bool $error): void {
    Event::swap(new Dispatcher(app()));
    $attempts = [];
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$attempts): never {
        $attempts[] = [$event->failed, $event->failure, $event->toolCall, $event->toolResult];
        throw new LogicException('synthetic-listener-outage');
    });
    $original = $error ? new Error('synthetic-native-secret') : new RuntimeException('synthetic-native-secret');
    $tool = (new Tool)->as('tool')->for('Probe')->withoutErrorHandling()->using(fn () => throw $original);
    $observed = null;
    try {
        (new FailureCallsToolsHandler)->execute([$tool], [new ToolCall('call', 'tool', [])]);
    } catch (Throwable $actual) {
        $observed = $actual;
    }
    expect($observed)->toBe($original)
        ->and($attempts)->toBe([[true, $error ? 'exception:Error' : 'exception:RuntimeException', null, null]]);
})->with([false, true]);

it('classifies approved tool calls once without repeating completed results on resume', function (): void {
    $body = '{"success":false}';
    $calls = 0;
    Prism::classifyToolResultsUsing(function () use (&$calls): string {
        $calls++;

        return 'approved.failed';
    });
    $tool = (new Tool)->as('tool')->for('Probe')->requiresApproval()->using(fn (): string => $body);
    $call = new ToolCall('call', 'tool', []);
    $request = new Request(model: 'test', messages: [
        new AssistantMessage('Approve', toolCalls: [$call], toolApprovalRequests: [new ToolApprovalRequest('approval', 'call')]),
        new ToolResultMessage([], [new ToolApprovalResponse('approval', true)]),
    ], tools: [$tool]);
    $handler = new FailureCallsToolsHandler;
    $events = iterator_to_array($handler->resume($request, 'message'));
    expect($calls)->toBe(1)->and($events[0]->success)->toBeFalse()->and($events[0]->toolResult->result)->toBe($body);
    Event::assertDispatchedTimes(ToolInvoked::class, 1);
    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => $event->failed && $event->failure === 'approved.failed');
    expect(iterator_to_array($handler->resume($request, 'message')))->toBe([])->and($calls)->toBe(1);
});

it('adds failure flags while preserving the content capture opt-in', function (): void {
    config()->set('prism.telemetry.capture_content', true);
    Prism::classifyToolResultsUsing(fn (): string => 'service.failed');
    $tool = (new Tool)->as('tool')->for('Probe')->using(fn (): string => 'synthetic customer data');
    $call = new ToolCall('call', 'tool', []);
    $results = (new FailureCallsToolsHandler)->execute([$tool], [$call]);
    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => $event->failed && $event->failure === 'service.failed'
        && $event->toolCall === $call && $event->toolResult === $results[0]);
});
