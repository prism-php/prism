<?php

declare(strict_types=1);

use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Artifact;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolOutput;
use Prism\Prism\ValueObjects\ToolResult;

class CallsToolsTestHandler
{
    use CallsTools;

    public function execute(array $tools, array $toolCalls): array
    {
        return $this->callTools($tools, $toolCalls);
    }

    public function stream(array $tools, array $toolCalls, string $messageId, array &$toolResults): Generator
    {
        return $this->callToolsAndYieldEvents($tools, $toolCalls, $messageId, $toolResults);
    }
}

it('executes tools and returns array of ToolResults', function (): void {
    $tool = (new Tool)
        ->as('greet')
        ->for('Greet a person')
        ->withStringParameter('name', 'Name to greet')
        ->using(fn (string $name): string => "Hello, {$name}!");

    $toolCall = new ToolCall(
        id: 'call-123',
        name: 'greet',
        arguments: ['name' => 'World'],
        resultId: 'result-456'
    );

    $handler = new CallsToolsTestHandler;
    $results = $handler->execute([$tool], [$toolCall]);

    expect($results)->toBeArray()
        ->and($results)->toHaveCount(1)
        ->and($results[0])->toBeInstanceOf(ToolResult::class)
        ->and($results[0]->toolCallId)->toBe('call-123')
        ->and($results[0]->toolName)->toBe('greet')
        ->and($results[0]->result)->toBe('Hello, World!')
        ->and($results[0]->args)->toBe(['name' => 'World'])
        ->and($results[0]->toolCallResultId)->toBe('result-456')
        ->and($results[0]->artifacts)->toBeEmpty();
});

it('generates tool events for streaming handlers', function (): void {
    $tool = (new Tool)
        ->as('calculate')
        ->for('Add two numbers')
        ->withNumberParameter('a', 'First number')
        ->withNumberParameter('b', 'Second number')
        ->using(fn (int $a, int $b): string => (string) ($a + $b));

    $toolCall = new ToolCall(id: 'call-456', name: 'calculate', arguments: ['a' => 5, 'b' => 3]);

    $handler = new CallsToolsTestHandler;
    $toolResults = [];
    $events = [];

    foreach ($handler->stream([$tool], [$toolCall], 'msg-123', $toolResults) as $event) {
        $events[] = $event;
    }

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ToolResultEvent::class)
        ->and($events[0]->success)->toBeTrue()
        ->and($events[0]->messageId)->toBe('msg-123')
        ->and($events[0]->toolResult->result)->toBe('8')
        ->and($toolResults)->toHaveCount(1)
        ->and($toolResults[0]->result)->toBe('8');
});

it('yields artifact events when tool returns artifacts', function (): void {
    $artifact1 = Artifact::fromRawContent('content 1', 'text/plain', ['name' => 'file1.txt']);
    $artifact2 = Artifact::fromRawContent('content 2', 'application/json', ['name' => 'data.json']);

    $tool = (new Tool)
        ->as('create_files')
        ->for('Create files')
        ->using(fn (): ToolOutput => new ToolOutput(
            result: 'Files created',
            artifacts: [$artifact1, $artifact2]
        ));

    $toolCall = new ToolCall(id: 'call-789', name: 'create_files', arguments: []);

    $handler = new CallsToolsTestHandler;
    $toolResults = [];
    $events = [];

    foreach ($handler->stream([$tool], [$toolCall], 'msg-123', $toolResults) as $event) {
        $events[] = $event;
    }

    expect($events)->toHaveCount(3)
        ->and($events[0])->toBeInstanceOf(ToolResultEvent::class)
        ->and($events[1])->toBeInstanceOf(ArtifactEvent::class)
        ->and($events[2])->toBeInstanceOf(ArtifactEvent::class)
        ->and($events[1]->artifact)->toBe($artifact1)
        ->and($events[1]->toolCallId)->toBe('call-789')
        ->and($events[1]->toolName)->toBe('create_files')
        ->and($events[2]->artifact)->toBe($artifact2);
});

it('yields failed ToolResultEvent when tool is not found', function (): void {
    $tool = (new Tool)
        ->as('existing_tool')
        ->for('An existing tool')
        ->using(fn (): string => 'result');

    $toolCall = new ToolCall(id: 'call-123', name: 'nonexistent_tool', arguments: []);

    $handler = new CallsToolsTestHandler;
    $toolResults = [];
    $events = [];

    foreach ($handler->stream([$tool], [$toolCall], 'msg-123', $toolResults) as $event) {
        $events[] = $event;
    }

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ToolResultEvent::class)
        ->and($events[0]->success)->toBeFalse()
        ->and($events[0]->error)->toContain('not found')
        ->and($events[0]->toolResult->result)->toContain('not found')
        ->and($toolResults)->toHaveCount(1);
});

it('yields failed ToolResultEvent when multiple tools with same name exist', function (): void {
    $tool1 = (new Tool)->as('duplicate')->for('First')->using(fn (): string => 'result 1');
    $tool2 = (new Tool)->as('duplicate')->for('Second')->using(fn (): string => 'result 2');

    $toolCall = new ToolCall(id: 'call-123', name: 'duplicate', arguments: []);

    $handler = new CallsToolsTestHandler;
    $toolResults = [];
    $events = [];

    foreach ($handler->stream([$tool1, $tool2], [$toolCall], 'msg-123', $toolResults) as $event) {
        $events[] = $event;
    }

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ToolResultEvent::class)
        ->and($events[0]->success)->toBeFalse()
        ->and($events[0]->error)->toContain('Multiple tools')
        ->and($toolResults)->toHaveCount(1);
});

it('continues processing other tool calls when one fails', function (): void {
    $tool = (new Tool)
        ->as('valid_tool')
        ->for('A valid tool')
        ->using(fn (): string => 'success');

    $toolCalls = [
        new ToolCall(id: 'call-1', name: 'nonexistent', arguments: []),
        new ToolCall(id: 'call-2', name: 'valid_tool', arguments: []),
        new ToolCall(id: 'call-3', name: 'another_nonexistent', arguments: []),
    ];

    $handler = new CallsToolsTestHandler;
    $results = $handler->execute([$tool], $toolCalls);

    expect($results)->toHaveCount(3)
        ->and($results[0]->result)->toContain('not found')
        ->and($results[1]->result)->toBe('success')
        ->and($results[2]->result)->toContain('not found');
});

it('throws non-PrismException errors', function (): void {
    $tool = (new Tool)
        ->as('throwing_tool')
        ->for('A tool that throws')
        ->using(function (): string {
            throw new RuntimeException('Unexpected error');
        })
        ->withoutErrorHandling();

    $toolCall = new ToolCall(id: 'call-123', name: 'throwing_tool', arguments: []);

    $handler = new CallsToolsTestHandler;

    expect(fn (): array => $handler->execute([$tool], [$toolCall]))
        ->toThrow(RuntimeException::class, 'Unexpected error');
});

it('collects results incrementally while yielding events', function (): void {
    $tool = (new Tool)
        ->as('echo')
        ->for('Echo input')
        ->withStringParameter('input', 'Input')
        ->using(fn (string $input): string => $input);

    $toolCalls = [
        new ToolCall(id: 'call-1', name: 'echo', arguments: ['input' => 'first']),
        new ToolCall(id: 'call-2', name: 'echo', arguments: ['input' => 'second']),
    ];

    $handler = new CallsToolsTestHandler;
    $toolResults = [];
    $toolResultEventCount = 0;

    foreach ($handler->stream([$tool], $toolCalls, 'msg-123', $toolResults) as $event) {
        if ($event instanceof ToolResultEvent) {
            $toolResultEventCount++;
            // Result is already in array before event is yielded
            expect($toolResults)->toHaveCount($toolResultEventCount)
                ->and($toolResults[$toolResultEventCount - 1])->toBe($event->toolResult);
        }
    }

    expect($toolResultEventCount)->toBe(2)
        ->and($toolResults[0]->result)->toBe('first')
        ->and($toolResults[1]->result)->toBe('second');
});

it('returns empty results when no tool calls provided', function (): void {
    $tool = (new Tool)->as('test')->for('Test')->using(fn (): string => 'result');

    $handler = new CallsToolsTestHandler;

    // Non-streaming
    expect($handler->execute([$tool], []))->toBeEmpty();

    // Streaming
    $toolResults = [];
    $events = iterator_to_array($handler->stream([$tool], [], 'msg-123', $toolResults));

    expect($events)->toBeEmpty()
        ->and($toolResults)->toBeEmpty();
});
