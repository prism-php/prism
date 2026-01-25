<?php

declare(strict_types=1);

use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;

class TestToolCaller
{
    use CallsTools {
        callToolsAndYieldEvents as public;
        groupToolCallsByConcurrency as public;
    }
}

beforeEach(function (): void {
    $this->caller = new TestToolCaller;
});

it('executes sequential tools in order', function (): void {
    $executionOrder = [];

    $tool1 = (new Tool)
        ->as('tool1')
        ->for('First tool')
        ->withParameter(new StringSchema('input', 'input'))
        ->using(function (string $input) use (&$executionOrder): string {
            $executionOrder[] = 'tool1';

            return "Result from tool1: $input";
        });

    $tool2 = (new Tool)
        ->as('tool2')
        ->for('Second tool')
        ->withParameter(new StringSchema('input', 'input'))
        ->using(function (string $input) use (&$executionOrder): string {
            $executionOrder[] = 'tool2';

            return "Result from tool2: $input";
        });

    $toolCalls = [
        new ToolCall('call1', 'tool1', ['input' => 'test1']),
        new ToolCall('call2', 'tool2', ['input' => 'test2']),
    ];

    $toolResults = [];
    $events = iterator_to_array($this->caller->callToolsAndYieldEvents(
        [$tool1, $tool2],
        $toolCalls,
        'msg123',
        $toolResults
    ));

    expect($toolResults)->toHaveCount(2);
    expect($toolResults[0]->toolName)->toBe('tool1');
    expect($toolResults[0]->result)->toBe('Result from tool1: test1');
    expect($toolResults[1]->toolName)->toBe('tool2');
    expect($toolResults[1]->result)->toBe('Result from tool2: test2');

    // Verify events are in order
    expect($events)->toHaveCount(2);
    expect($events[0])->toBeInstanceOf(ToolResultEvent::class);
    expect($events[0]->toolResult->toolName)->toBe('tool1');
    expect($events[1])->toBeInstanceOf(ToolResultEvent::class);
    expect($events[1]->toolResult->toolName)->toBe('tool2');
});

it('executes concurrent tools in parallel but maintains event order', function (): void {
    $tool1 = (new Tool)
        ->as('tool1')
        ->for('First tool')
        ->withParameter(new StringSchema('input', 'input'))
        ->using(fn (string $input): string => "Result from tool1: $input")
        ->concurrent();

    $tool2 = (new Tool)
        ->as('tool2')
        ->for('Second tool')
        ->withParameter(new StringSchema('input', 'input'))
        ->using(fn (string $input): string => "Result from tool2: $input")
        ->concurrent();

    $tool3 = (new Tool)
        ->as('tool3')
        ->for('Third tool')
        ->withParameter(new StringSchema('input', 'input'))
        ->using(fn (string $input): string => "Result from tool3: $input")
        ->concurrent();

    $toolCalls = [
        new ToolCall('call1', 'tool1', ['input' => 'test1']),
        new ToolCall('call2', 'tool2', ['input' => 'test2']),
        new ToolCall('call3', 'tool3', ['input' => 'test3']),
    ];

    $toolResults = [];
    $events = iterator_to_array($this->caller->callToolsAndYieldEvents(
        [$tool1, $tool2, $tool3],
        $toolCalls,
        'msg123',
        $toolResults
    ));

    // Verify results are in original order despite parallel execution
    expect($toolResults)->toHaveCount(3);
    expect($toolResults[0]->toolName)->toBe('tool1');
    expect($toolResults[1]->toolName)->toBe('tool2');
    expect($toolResults[2]->toolName)->toBe('tool3');

    // Verify events are in original order
    expect($events)->toHaveCount(3);
    expect($events[0]->toolResult->toolName)->toBe('tool1');
    expect($events[1]->toolResult->toolName)->toBe('tool2');
    expect($events[2]->toolResult->toolName)->toBe('tool3');
});

it('handles mixed concurrent and sequential tools correctly', function (): void {
    $executionLog = [];

    $concurrentTool1 = (new Tool)
        ->as('concurrent1')
        ->for('Concurrent tool 1')
        ->withParameter(new StringSchema('input', 'input'))
        ->using(function (string $input) use (&$executionLog): string {
            $executionLog[] = ['tool' => 'concurrent1', 'time' => microtime(true)];

            return "Concurrent result 1: $input";
        })
        ->concurrent();

    $sequentialTool = (new Tool)
        ->as('sequential')
        ->for('Sequential tool')
        ->withParameter(new StringSchema('input', 'input'))
        ->using(function (string $input) use (&$executionLog): string {
            $executionLog[] = ['tool' => 'sequential', 'time' => microtime(true)];

            return "Sequential result: $input";
        });

    $concurrentTool2 = (new Tool)
        ->as('concurrent2')
        ->for('Concurrent tool 2')
        ->withParameter(new StringSchema('input', 'input'))
        ->using(function (string $input) use (&$executionLog): string {
            $executionLog[] = ['tool' => 'concurrent2', 'time' => microtime(true)];

            return "Concurrent result 2: $input";
        })
        ->concurrent();

    $toolCalls = [
        new ToolCall('call1', 'concurrent1', ['input' => 'test1']),
        new ToolCall('call2', 'sequential', ['input' => 'test2']),
        new ToolCall('call3', 'concurrent2', ['input' => 'test3']),
    ];

    $toolResults = [];
    $events = iterator_to_array($this->caller->callToolsAndYieldEvents(
        [$concurrentTool1, $sequentialTool, $concurrentTool2],
        $toolCalls,
        'msg123',
        $toolResults
    ));

    // Verify all tools executed
    expect($toolResults)->toHaveCount(3);
    expect($executionLog)->toHaveCount(3);

    // Verify results maintain original order
    expect($toolResults[0]->toolName)->toBe('concurrent1');
    expect($toolResults[1]->toolName)->toBe('sequential');
    expect($toolResults[2]->toolName)->toBe('concurrent2');

    // Verify events maintain order
    expect($events[0]->toolResult->toolName)->toBe('concurrent1');
    expect($events[1]->toolResult->toolName)->toBe('sequential');
    expect($events[2]->toolResult->toolName)->toBe('concurrent2');
});

it('handles errors in concurrent tools while maintaining order', function (): void {
    $tool1 = (new Tool)
        ->as('success_tool')
        ->for('Success tool')
        ->withParameter(new StringSchema('input', 'input'))
        ->using(fn (string $input): string => "Success: $input")
        ->concurrent();

    $tool2 = (new Tool)
        ->as('nonexistent_tool')
        ->for('This tool won\'t be found')
        ->using(fn (): string => 'should not run')
        ->concurrent();

    $tool3 = (new Tool)
        ->as('another_success')
        ->for('Another success')
        ->withParameter(new StringSchema('input', 'input'))
        ->using(fn (string $input): string => "Another success: $input")
        ->concurrent();

    $toolCalls = [
        new ToolCall('call1', 'success_tool', ['input' => 'test1']),
        new ToolCall('call2', 'error_tool', ['input' => 'test2']), // Tool not in array - will fail
        new ToolCall('call3', 'another_success', ['input' => 'test3']),
    ];

    $toolResults = [];
    $events = iterator_to_array($this->caller->callToolsAndYieldEvents(
        [$tool1, $tool3],
        $toolCalls,
        'msg123',
        $toolResults
    ));

    // All tool calls should have results (including error)
    expect($toolResults)->toHaveCount(3);

    expect($toolResults[0]->toolName)->toBe('success_tool');
    expect($toolResults[0]->result)->toBe('Success: test1');

    expect($toolResults[1]->toolName)->toBe('error_tool');
    expect($toolResults[1]->result)->toContain('not found');

    expect($toolResults[2]->toolName)->toBe('another_success');
    expect($toolResults[2]->result)->toBe('Another success: test3');

    // Verify events maintain order
    expect($events[0]->toolResult->toolName)->toBe('success_tool');
    expect($events[0]->success)->toBeTrue();

    expect($events[1]->toolResult->toolName)->toBe('error_tool');
    expect($events[1]->success)->toBeFalse();
    expect($events[1]->error)->toContain('not found');

    expect($events[2]->toolResult->toolName)->toBe('another_success');
    expect($events[2]->success)->toBeTrue();
});

it('groups tools correctly by concurrency status', function (): void {
    $concurrentTool = (new Tool)
        ->as('concurrent')
        ->for('Concurrent')
        ->withParameter(new StringSchema('input', 'input'))
        ->using(fn (string $input): string => "Concurrent: $input")
        ->concurrent();

    $sequentialTool = (new Tool)
        ->as('sequential')
        ->for('Sequential')
        ->withParameter(new StringSchema('input', 'input'))
        ->using(fn (string $input): string => "Sequential: $input");

    $toolCalls = [
        new ToolCall('call1', 'concurrent', ['input' => 'test1']),
        new ToolCall('call2', 'sequential', ['input' => 'test2']),
    ];

    $grouped = $this->caller->groupToolCallsByConcurrency(
        [$concurrentTool, $sequentialTool],
        $toolCalls
    );

    expect($grouped)->toHaveKeys(['concurrent', 'sequential']);
    expect($grouped['concurrent'])->toHaveCount(1);
    expect($grouped['sequential'])->toHaveCount(1);
    expect($grouped['concurrent'][0]->name)->toBe('concurrent');
    expect($grouped['sequential'][1]->name)->toBe('sequential');
});
