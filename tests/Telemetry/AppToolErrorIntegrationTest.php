<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;

class AppErrorCallsTools
{
    use CallsTools {
        callTools as public execute;
        callToolsAndYieldEvents as public stream;
    }
}

beforeEach(function (): void {
    Prism::handleToolErrorsUsing(null);
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', false);
    Event::fake([ToolInvoked::class]);
    Telemetry::start(TelemetryOperation::Text, 'openai', 'fixture');
});

afterEach(function (): void {
    Prism::handleToolErrorsUsing(null);
    Prism::classifyToolResultsUsing(null);
    Telemetry::end(Telemetry::current());
});

it('keeps handled failures redacted and preserves kinds across normal and streamed tool loops', function (bool $concurrent, bool $stream, string $policy): void {
    Prism::handleToolErrorsUsing(match ($policy) {
        'valid' => fn (): string => 'Safe app failure.',
        'throws' => fn () => throw new LogicException('synthetic-callback-secret'),
        'non-string' => fn (): array => ['secret' => 'synthetic-callback-secret'],
    });
    $classifierCalls = 0;
    Prism::classifyToolResultsUsing(function () use (&$classifierCalls): string {
        $classifierCalls++;

        return 'must_not_override';
    });
    $tool = (new Tool)->as('probe')->for('Probe')->concurrent($concurrent)
        ->using(fn () => throw new RuntimeException('synthetic-provider-billing-secret'));
    $results = [];
    $call = new ToolCall('call', 'probe', []);
    $expected = $policy === 'valid' ? 'Safe app failure.' : 'The tool failed.';
    if ($stream) {
        $events = iterator_to_array((new AppErrorCallsTools)->stream([$tool], [$call], 'message', $results));
        expect($events)->toHaveCount(1)->and($events[0]->success)->toBeFalse()->and($events[0]->error)->toBe($expected)
            ->and(json_encode($events[0]->toArray(), JSON_THROW_ON_ERROR))->not->toContain('secret');
    } else {
        $results = (new AppErrorCallsTools)->execute([$tool], [$call]);
    }
    expect($results)->toHaveCount(1)->and($results[0]->result)->toBe($expected)->and($classifierCalls)->toBe(0);
    Event::assertDispatchedTimes(ToolInvoked::class, 1);
    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => $event->failed
        && $event->failure === 'tool_error');
    $event = Event::dispatched(ToolInvoked::class)->last()[0];
    expect($event->toolCall)->toBeNull()->and($event->toolResult)->toBeNull();
})->with([false, true], [false, true], ['valid', 'throws', 'non-string']);

it('keeps returned failures under the existing classifier without invoking the application handler', function (): void {
    $handlerCalls = 0;
    Prism::handleToolErrorsUsing(function () use (&$handlerCalls): string {
        $handlerCalls++;

        return 'Must not rewrite this result.';
    });
    Prism::classifyToolResultsUsing(fn (): string => 'remote_failure');
    $body = '{"success":false,"error":"consumer-owned"}';
    $tool = (new Tool)->as('probe')->using(fn (): string => $body);
    $results = (new AppErrorCallsTools)->execute([$tool], [new ToolCall('call', 'probe', [])]);
    expect($results[0]->result)->toBe($body)->and($handlerCalls)->toBe(0);
    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => $event->failed && $event->failure === 'remote_failure');
});

it('keeps thrown details out of the next provider request', function (bool $broken): void {
    Http::swap(new Factory);
    $sequence = Http::sequence();
    foreach ([1, 2] as $index) {
        $sequence->push(file_get_contents(__DIR__.'/../Fixtures/openai/generate-text-with-multiple-tools-'.$index.'.json'), 200, ['Content-Type' => 'application/json']);
    }
    Http::fake(['*' => $sequence])->preventStrayRequests();
    Prism::handleToolErrorsUsing($broken ? fn () => throw new LogicException('synthetic-callback-secret') : fn (): string => 'Safe app failure.');
    $tools = [
        (new Tool)->as('weather')->for('Weather')->withStringParameter('city', 'City')->using(fn (string $city) => throw new RuntimeException('synthetic-billing-secret')),
        (new Tool)->as('search')->for('Search')->withStringParameter('query', 'Query')->using(fn (string $query) => throw new RuntimeException('synthetic-sql-host-secret')),
    ];
    $response = Prism::text()->using('openai', 'fixture')->withTools($tools)->withMaxSteps(3)->withPrompt('Use the tools')->asText();
    $expected = $broken ? 'The tool failed.' : 'Safe app failure.';
    expect($response->steps)->toHaveCount(2)->and($response->steps[0]->toolResults)->toHaveCount(2);
    foreach ($response->steps[0]->toolResults as $result) {
        expect($result->result)->toBe($expected);
    }
    Http::assertSentCount(2);
    $request = Http::recorded()->last()[0];
    $outputs = array_values(array_filter($request->data()['input'], fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'));
    expect($outputs)->toHaveCount(2)->and(array_column($outputs, 'output'))->toBe([$expected, $expected])
        ->and($request->body())->not->toContain('synthetic-billing-secret', 'synthetic-sql-host-secret', 'synthetic-callback-secret');
    Event::assertDispatchedTimes(ToolInvoked::class, 2);
})->with([false, true]);
