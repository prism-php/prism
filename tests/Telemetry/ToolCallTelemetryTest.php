<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Telemetry\Events\ToolCallCompleted;
use Prism\Prism\Telemetry\Events\ToolCallStarted;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;

it('emits telemetry events for tool calls when enabled', function (): void {
    config([
        'prism.telemetry.enabled' => true,
        'prism.telemetry.driver' => 'null',
    ]);

    Event::fake();

    // Create a test class that uses the CallsTools trait
    $testHandler = new class
    {
        use CallsTools;

        public function testCallTools(array $tools, array $toolCalls): array
        {
            return $this->callTools($tools, $toolCalls);
        }
    };

    // Create a mock tool
    $tool = (new Tool)
        ->as('test_tool')
        ->for('Testing tool calls')
        ->withStringParameter('input', 'Test input')
        ->using(fn (string $input): string => "Processed: {$input}");

    // Create a tool call
    $toolCall = new ToolCall(
        id: 'tool-123',
        name: 'test_tool',
        arguments: ['input' => 'test value'],
        resultId: 'result-123'
    );

    // Execute the tool call
    $results = $testHandler->testCallTools([$tool], [$toolCall]);

    // Verify tool call telemetry events were dispatched
    Event::assertDispatched(ToolCallStarted::class);
    Event::assertDispatched(ToolCallCompleted::class);

    expect($results)->toHaveCount(1);
});

it('does not emit tool call events when telemetry is disabled', function (): void {
    config([
        'prism.telemetry.enabled' => false,
    ]);

    Event::fake();

    // Create a test class that uses the CallsTools trait
    $testHandler = new class
    {
        use CallsTools;

        public function testCallTools(array $tools, array $toolCalls): array
        {
            return $this->callTools($tools, $toolCalls);
        }
    };

    // Create a mock tool
    $tool = (new Tool)
        ->as('test_tool')
        ->for('Testing tool calls')
        ->withStringParameter('input', 'Test input')
        ->using(fn (string $input): string => "Processed: {$input}");

    // Create a tool call
    $toolCall = new ToolCall(
        id: 'tool-123',
        name: 'test_tool',
        arguments: ['input' => 'test value'],
        resultId: 'result-123'
    );

    // Execute the tool call
    $results = $testHandler->testCallTools([$tool], [$toolCall]);

    // Verify tool call events were not dispatched when telemetry is disabled
    Event::assertNotDispatched(ToolCallStarted::class);
    Event::assertNotDispatched(ToolCallCompleted::class);

    expect($results)->toHaveCount(1);
});

it('includes traceId and parentSpanId in tool call telemetry events', function (): void {
    config([
        'prism.telemetry.enabled' => true,
        'prism.telemetry.driver' => 'null',
    ]);

    Event::fake();

    // Create a test class that uses the CallsTools trait
    $testHandler = new class
    {
        use CallsTools;

        public function testCallTools(array $tools, array $toolCalls): array
        {
            return $this->callTools($tools, $toolCalls);
        }
    };

    // Create a mock tool
    $tool = (new Tool)
        ->as('test_tool')
        ->for('Testing tool calls')
        ->withStringParameter('input', 'Test input')
        ->using(fn (string $input): string => "Processed: {$input}");

    // Create a tool call
    $toolCall = new ToolCall(
        id: 'tool-123',
        name: 'test_tool',
        arguments: ['input' => 'test value'],
        resultId: 'result-123'
    );

    // Execute the tool call
    $results = $testHandler->testCallTools([$tool], [$toolCall]);

    // Verify tool call events contain traceId and parentSpanId as properties
    Event::assertDispatched(ToolCallStarted::class, function (ToolCallStarted $event): bool {
        return ! empty($event->spanId)
            && ! empty($event->traceId)
            && $event->parentSpanId === null; // No parent when called directly
    });

    Event::assertDispatched(ToolCallCompleted::class, fn (ToolCallCompleted $event): bool => ! empty($event->spanId)
        && ! empty($event->traceId)
        && $event->parentSpanId === null);

    expect($results)->toHaveCount(1);
});
