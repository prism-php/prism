<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Streaming\Adapters\SSEAdapter;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @param  array<StreamEvent>  $events
 */
function createEventGenerator(array $events): Generator
{
    foreach ($events as $event) {
        yield $event;
    }
}

it('creates SSE response with correct headers and structure', function (): void {
    $events = [
        new TextDeltaEvent('evt-123', 1640995200, 'Hello', 'msg-456'),
    ];

    $adapter = new SSEAdapter;
    $response = ($adapter)(createEventGenerator($events));

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('Content-Type'))->toBe('text/event-stream');
    expect($response->headers->get('Cache-Control'))->toContain('no-cache');
    expect($response->headers->get('X-Accel-Buffering'))->toBe('no');
    expect($response->headers->get('Connection'))->toBe('keep-alive');

    // Verify the response has a callback function (streaming capability)
    expect($response->getCallback())->toBeCallable();
});

it('accepts event generator and maintains event types', function (): void {
    $events = [
        new StreamStartEvent('evt-1', 1640995200, 'gpt-4', 'openai'),
        new TextDeltaEvent('evt-2', 1640995201, 'Hello', 'msg-456'),
        new StreamEndEvent('evt-3', 1640995202, FinishReason::Stop),
    ];

    $adapter = new SSEAdapter;

    // Test that adapter accepts the generator without error
    expect($adapter)->toBeInstanceOf(SSEAdapter::class);

    // Test that we can create a response
    $response = ($adapter)(createEventGenerator($events));
    expect($response)->toBeInstanceOf(StreamedResponse::class);
});

it('handles different event types without errors', function (): void {
    // Test with various event combinations that should not cause errors
    $events = [
        new StreamStartEvent('evt-1', 1640995200, 'claude-3', 'anthropic'),
        new TextStartEvent('evt-2', 1640995201, 'msg-456'),
        new ThinkingStartEvent('evt-3', 1640995202, 'reasoning-123'),
        new ThinkingEvent('evt-4', 1640995203, 'Thinking...', 'reasoning-123'),
        new ToolCallEvent('evt-5', 1640995204, new ToolCall('tool-123', 'search', ['q' => 'test']), 'msg-456'),
        new ToolResultEvent('evt-6', 1640995205, new ToolResult('tool-123', 'search', ['q' => 'test'], ['result' => 'found']), 'msg-456', true),
        new ErrorEvent('evt-7', 1640995206, 'test_error', 'Test error', true),
        new StreamEndEvent('evt-8', 1640995207, FinishReason::Stop),
    ];

    $adapter = new SSEAdapter;
    $response = ($adapter)(createEventGenerator($events));

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->getStatusCode())->toBe(200);
});

it('handles empty event stream without errors', function (): void {
    $adapter = new SSEAdapter;
    $response = ($adapter)(createEventGenerator([]));

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->getStatusCode())->toBe(200);
    expect($response->getCallback())->toBeCallable();
});

it('processes events with complex data structures', function (): void {
    $complexArgs = [
        'query' => 'search term',
        'options' => [
            'limit' => 10,
            'sort' => 'relevance',
            'filters' => ['category' => 'tech', 'date' => '2024'],
        ],
        'metadata' => null,
    ];

    $events = [
        new ToolCallEvent('evt-1', 1640995200, new ToolCall('tool-123', 'complex_search', $complexArgs), 'msg-456'),
        new ToolResultEvent('evt-2', 1640995201, new ToolResult('tool-123', 'complex_search', $complexArgs, ['data' => ['nested' => ['value' => 123]]]), 'msg-456', true),
    ];

    $adapter = new SSEAdapter;
    $response = ($adapter)(createEventGenerator($events));

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->getStatusCode())->toBe(200);
});

it('handles events with unicode and special characters', function (): void {
    $events = [
        new TextDeltaEvent('evt-1', 1640995200, '🚀 Hello 世界! "quoted" text', 'msg-456'),
        new ThinkingEvent('evt-2', 1640995201, 'Thinking with émojis 🤔', 'reasoning-123'),
    ];

    $adapter = new SSEAdapter;
    $response = ($adapter)(createEventGenerator($events));

    // Should handle unicode without throwing errors
    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->getStatusCode())->toBe(200);
});

it('maintains correct SSE format structure', function (): void {
    $event = new TextDeltaEvent('evt-123', 1640995200, 'Hello world!', 'msg-456');
    $adapter = new SSEAdapter;

    $response = ($adapter)(createEventGenerator([$event]));
    $callback = $response->getCallback();

    // Test that the callback is properly structured for SSE
    expect($callback)->toBeCallable();

    // Test callback execution without polluting output
    // We'll capture and discard the output to prevent it from showing in tests
    $outputBuffer = fopen('php://memory', 'r+');
    ob_start(function ($buffer) use ($outputBuffer): string {
        fwrite($outputBuffer, $buffer);

        return ''; // Return empty string to prevent output
    });

    try {
        $callback();
        ob_end_flush();

        // Verify some output was generated (callback actually ran)
        rewind($outputBuffer);
        $capturedOutput = stream_get_contents($outputBuffer);

        expect(strlen($capturedOutput))->toBeGreaterThan(0);

        // Verify correct SSE format
        expect($capturedOutput)->toContain('event: text_delta');
        expect($capturedOutput)->toContain('data: ');
        expect($capturedOutput)->toContain('"delta":"Hello world!"');
        expect($capturedOutput)->toContain('"message_id":"msg-456"');
        expect($capturedOutput)->toEndWith("\n\n");
    } finally {
        fclose($outputBuffer);
    }
});

it('formats multiple events with correct SSE structure', function (): void {
    $events = [
        new StreamStartEvent('evt-1', 1640995200, 'gpt-4', 'openai'),
        new TextDeltaEvent('evt-2', 1640995201, 'Hello', 'msg-456'),
        new TextDeltaEvent('evt-3', 1640995202, ' world!', 'msg-456'),
        new StreamEndEvent('evt-4', 1640995203, FinishReason::Stop),
    ];

    $adapter = new SSEAdapter;
    $response = ($adapter)(createEventGenerator($events));
    $callback = $response->getCallback();

    $outputBuffer = fopen('php://memory', 'r+');
    ob_start(function ($buffer) use ($outputBuffer): string {
        fwrite($outputBuffer, $buffer);

        return '';
    });

    try {
        $callback();
        ob_end_flush();

        rewind($outputBuffer);
        $capturedOutput = stream_get_contents($outputBuffer);

        // Verify each event is properly formatted
        expect($capturedOutput)->toContain("event: stream_start\ndata: ");
        expect($capturedOutput)->toContain("event: text_delta\ndata: ");
        expect($capturedOutput)->toContain("event: stream_end\ndata: ");

        // Verify JSON structure in data fields
        expect($capturedOutput)->toContain('"model":"gpt-4"');
        expect($capturedOutput)->toContain('"provider":"openai"');
        expect($capturedOutput)->toContain('"delta":"Hello"');
        expect($capturedOutput)->toContain('"delta":" world!"');
        expect($capturedOutput)->toContain('"finish_reason":"Stop"');

        // Verify proper SSE format (each event ends with double newline)
        $eventBlocks = explode("\n\n", trim($capturedOutput));
        expect(count($eventBlocks))->toBe(4); // 4 events

        foreach ($eventBlocks as $block) {
            expect($block)->toMatch('/^event: [\w-]+\ndata: \{.*\}$/');
        }
    } finally {
        fclose($outputBuffer);
    }
});

it('integrates with Laravel response system', function (): void {
    $events = [
        new StreamStartEvent('evt-1', 1640995200, 'gpt-4', 'openai'),
        new StreamEndEvent('evt-2', 1640995201, FinishReason::Stop),
    ];

    $adapter = new SSEAdapter;
    $response = ($adapter)(createEventGenerator($events));

    // Test integration with Laravel's response system
    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->getStatusCode())->toBe(200);

    // Verify critical SSE headers are set
    expect($response->headers->has('Content-Type'))->toBeTrue();
    expect($response->headers->has('Cache-Control'))->toBeTrue();
    expect($response->headers->has('Connection'))->toBeTrue();
});
