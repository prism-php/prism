<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\Adapters\DataProtocolAdapter;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\ValueObjects\Artifact;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @param  array<StreamEvent>  $events
 */
function createDataEventGenerator(array $events): Generator
{
    foreach ($events as $event) {
        yield $event;
    }
}

/**
 * Creates a generator that throws an exception after yielding some events.
 *
 * @param  array<StreamEvent>  $eventsBeforeError
 */
function createThrowingGenerator(array $eventsBeforeError, Throwable $exception): Generator
{
    foreach ($eventsBeforeError as $event) {
        yield $event;
    }

    throw $exception;
}

it('creates data protocol response with correct headers and structure', function (): void {
    $events = [
        new TextDeltaEvent('evt-123', 1640995200, 'Hello', 'msg-456'),
    ];

    $adapter = new DataProtocolAdapter;
    $response = ($adapter)(createDataEventGenerator($events));

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('Content-Type'))->toBe('text/plain; charset=utf-8');
    expect($response->headers->get('Cache-Control'))->toContain('no-cache, no-transform');
    expect($response->headers->get('X-Accel-Buffering'))->toBe('no');
    expect($response->headers->get('x-vercel-ai-ui-message-stream'))->toBe('v1');

    // Verify the response has a callback function (streaming capability)
    expect($response->getCallback())->toBeCallable();
});

it('accepts event generator and maintains event types', function (): void {
    $events = [
        new StreamStartEvent('evt-1', 1640995200, 'gpt-4', 'openai'),
        new TextDeltaEvent('evt-2', 1640995201, 'Hello', 'msg-456'),
        new StreamEndEvent('evt-3', 1640995202, FinishReason::Stop),
    ];

    $adapter = new DataProtocolAdapter;

    // Test that adapter accepts the generator without error
    expect($adapter)->toBeInstanceOf(DataProtocolAdapter::class);

    // Test that we can create a response
    $response = ($adapter)(createDataEventGenerator($events));
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

    $adapter = new DataProtocolAdapter;
    $response = ($adapter)(createDataEventGenerator($events));

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->getStatusCode())->toBe(200);
});

it('handles empty event stream without errors', function (): void {
    $adapter = new DataProtocolAdapter;
    $response = ($adapter)(createDataEventGenerator([]));

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

    $adapter = new DataProtocolAdapter;
    $response = ($adapter)(createDataEventGenerator($events));

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->getStatusCode())->toBe(200);
});

it('handles events with unicode and special characters', function (): void {
    $events = [
        new TextDeltaEvent('evt-1', 1640995200, '🚀 Hello 世界! "quoted" text', 'msg-456'),
        new ThinkingEvent('evt-2', 1640995201, 'Thinking with émojis 🤔', 'reasoning-123'),
    ];

    $adapter = new DataProtocolAdapter;
    $response = ($adapter)(createDataEventGenerator($events));

    // Should handle unicode without throwing errors
    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->getStatusCode())->toBe(200);
});

it('maintains correct data protocol format structure', function (): void {
    $event = new TextDeltaEvent('evt-123', 1640995200, 'Hello world!', 'msg-456');
    $adapter = new DataProtocolAdapter;

    $response = ($adapter)(createDataEventGenerator([$event]));
    $callback = $response->getCallback();

    // Test that the callback is properly structured
    expect($callback)->toBeCallable();

    // Test callback execution without polluting output
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

        // Verify correct Data Protocol format
        expect($capturedOutput)->toContain('data: {"type":"text-delta"');
        expect($capturedOutput)->toContain('"delta":"Hello world!"');
        expect($capturedOutput)->toContain('"id":"msg-456"');
        expect($capturedOutput)->toContain('data: [DONE]');
        expect($capturedOutput)->toEndWith("data: [DONE]\n\n");
    } finally {
        fclose($outputBuffer);
    }
});

it('integrates with Laravel response system', function (): void {
    $events = [
        new StreamStartEvent('evt-1', 1640995200, 'gpt-4', 'openai'),
        new StreamEndEvent('evt-2', 1640995201, FinishReason::Stop),
    ];

    $adapter = new DataProtocolAdapter;
    $response = ($adapter)(createDataEventGenerator($events));

    // Test integration with Laravel's response system
    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->headers)->toBeInstanceOf(ResponseHeaderBag::class);
    expect($response->getStatusCode())->toBe(200);

    // Verify critical data protocol headers are set
    expect($response->headers->has('Content-Type'))->toBeTrue();
    expect($response->headers->has('Cache-Control'))->toBeTrue();
    expect($response->headers->has('x-vercel-ai-ui-message-stream'))->toBeTrue();
});

it('handles JSON encoding errors gracefully', function (): void {
    // Create an event with data that might cause JSON encoding issues
    $event = new ToolCallEvent(
        'evt-1',
        1640995200,
        new ToolCall('tool-123', 'test', ['binary' => "\x80\x81\x82"]), // Invalid UTF-8 sequence
        'msg-456'
    );

    $adapter = new DataProtocolAdapter;
    $response = ($adapter)(createDataEventGenerator([$event]));
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

        // Should output error event instead of throwing exception
        expect($capturedOutput)->toContain('data: {"type":"error"');
        expect($capturedOutput)->toContain('Failed to encode event data as JSON');
        expect($capturedOutput)->toContain('data: [DONE]');
    } finally {
        fclose($outputBuffer);
    }
});

it('formats multiple events with correct data protocol structure', function (): void {
    $usage = new Usage(promptTokens: 10, completionTokens: 5);

    $events = [
        new StreamStartEvent('evt-1', 1640995200, 'claude-3', 'anthropic'),
        new TextDeltaEvent('evt-2', 1640995201, 'Hello', 'msg-456'),
        new TextDeltaEvent('evt-3', 1640995202, ' world!', 'msg-456'),
        new StreamEndEvent('evt-4', 1640995203, FinishReason::Stop, $usage),
    ];

    $adapter = new DataProtocolAdapter;
    $response = ($adapter)(createDataEventGenerator($events));
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

        // Verify each event is properly formatted as Data Protocol
        expect($capturedOutput)->toContain('data: {"type":"start"');
        expect($capturedOutput)->toContain('data: {"type":"text-delta"');
        expect($capturedOutput)->toContain('data: {"type":"finish"');
        expect($capturedOutput)->toContain('data: [DONE]');

        // Verify JSON structure in data fields
        expect($capturedOutput)->toContain('"messageId":"evt-1"'); // StreamStart uses messageId
        expect($capturedOutput)->toContain('"delta":"Hello"');
        expect($capturedOutput)->toContain('"delta":" world!"');
        expect($capturedOutput)->toContain('"messageMetadata":{"finishReason":"stop","usage":{"promptTokens":10,"completionTokens":5}}');

        // Verify proper Data Protocol format (each line starts with "data: " and ends with newline)
        $lines = explode("\n", trim($capturedOutput));
        $dataLines = array_filter($lines, fn (string $line): bool => str_starts_with($line, 'data: '));
        expect(count($dataLines))->toBeGreaterThanOrEqual(5); // 4 events + [DONE]

        foreach ($dataLines as $line) {
            if ($line === 'data: [DONE]') {
                expect($line)->toBe('data: [DONE]');
            } else {
                expect($line)->toMatch('/^data: \{.*\}$/');
                // Verify it's valid JSON after "data: " prefix
                $jsonPart = substr($line, 6); // Remove "data: " prefix
                expect(json_decode($jsonPart))->not->toBeNull();
            }
        }

        // Verify stream ends with [DONE]
        expect($capturedOutput)->toEndWith("data: [DONE]\n\n");
    } finally {
        fclose($outputBuffer);
    }
});

it('converts events to correct data protocol format', function (): void {
    $usage = new Usage(promptTokens: 10, completionTokens: 5);

    $events = [
        new StreamStartEvent('evt-1', 1640995200, 'claude-3', 'anthropic'),
        new TextStartEvent('evt-2', 1640995201, 'msg-456'),
        new TextDeltaEvent('evt-3', 1640995202, 'Hello', 'msg-456'),
        new ThinkingStartEvent('evt-4', 1640995203, 'reasoning-123'),
        new ThinkingEvent('evt-5', 1640995204, 'Thinking...', 'reasoning-123'),
        new ThinkingCompleteEvent('evt-6', 1640995205, 'reasoning-123'),
        new ToolCallEvent('evt-7', 1640995206, new ToolCall('tool-123', 'search', ['q' => 'test']), 'msg-456'),
        new ToolResultEvent('evt-8', 1640995207, new ToolResult('tool-123', 'search', ['q' => 'test'], ['result' => 'found']), 'msg-456', true),
        new TextCompleteEvent('evt-9', 1640995208, 'msg-456'),
        new StreamEndEvent('evt-10', 1640995209, FinishReason::Stop, $usage),
    ];

    $adapter = new DataProtocolAdapter;
    $response = ($adapter)(createDataEventGenerator($events));
    $callback = $response->getCallback();

    // Test that conversion doesn't throw errors and produces expected format
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

        // Verify data protocol specific format elements
        expect($capturedOutput)->toContain('data: {"type":"start"');
        expect($capturedOutput)->toContain('data: {"type":"text-start"');
        expect($capturedOutput)->toContain('data: {"type":"text-delta"');
        expect($capturedOutput)->toContain('data: {"type":"reasoning-start"');
        expect($capturedOutput)->toContain('data: {"type":"reasoning-delta"');
        expect($capturedOutput)->toContain('data: {"type":"reasoning-end"');
        expect($capturedOutput)->toContain('data: {"type":"tool-input-available"');
        expect($capturedOutput)->toContain('data: {"type":"tool-output-available"');
        expect($capturedOutput)->toContain('data: {"type":"text-end"');
        expect($capturedOutput)->toContain('data: {"type":"finish"');
        expect($capturedOutput)->toContain('data: [DONE]');
    } finally {
        fclose($outputBuffer);
    }
});

it('catches exceptions during streaming and outputs error event', function (): void {
    $eventsBeforeError = [
        new StreamStartEvent('evt-1', 1640995200, 'claude-3', 'anthropic'),
        new TextDeltaEvent('evt-2', 1640995201, 'Hello', 'msg-456'),
    ];

    $exception = new RuntimeException('API connection failed');

    $adapter = new DataProtocolAdapter;
    $response = ($adapter)(createThrowingGenerator($eventsBeforeError, $exception));
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

        // Verify events before error were streamed
        expect($capturedOutput)->toContain('data: {"type":"start"');
        expect($capturedOutput)->toContain('data: {"type":"text-delta"');

        // Verify error event was output
        expect($capturedOutput)->toContain('data: {"type":"error"');
        expect($capturedOutput)->toContain('"errorText":"API connection failed"');

        // Verify stream was properly terminated
        expect($capturedOutput)->toContain('data: [DONE]');
        expect($capturedOutput)->toEndWith("data: [DONE]\n\n");
    } finally {
        fclose($outputBuffer);
    }
});

it('catches exceptions at the start of streaming and outputs error event', function (): void {
    $exception = new RuntimeException('Authentication failed');

    $adapter = new DataProtocolAdapter;
    $response = ($adapter)(createThrowingGenerator([], $exception));
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

        // Verify error event was output
        expect($capturedOutput)->toContain('data: {"type":"error"');
        expect($capturedOutput)->toContain('"errorText":"Authentication failed"');

        // Verify stream was properly terminated
        expect($capturedOutput)->toContain('data: [DONE]');
    } finally {
        fclose($outputBuffer);
    }
});

it('handles Prism exceptions during streaming gracefully', function (): void {
    $eventsBeforeError = [
        new TextDeltaEvent('evt-1', 1640995200, 'Partial response', 'msg-456'),
    ];

    $exception = new PrismException('Rate limit exceeded');

    $adapter = new DataProtocolAdapter;
    $response = ($adapter)(createThrowingGenerator($eventsBeforeError, $exception));
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

        // Verify partial content was streamed
        expect($capturedOutput)->toContain('"delta":"Partial response"');

        // Verify error was captured and output as SSE event
        expect($capturedOutput)->toContain('data: {"type":"error"');
        expect($capturedOutput)->toContain('Rate limit exceeded');

        // Verify proper termination
        expect($capturedOutput)->toEndWith("data: [DONE]\n\n");
    } finally {
        fclose($outputBuffer);
    }
});

it('includes ErrorEvent in collected events passed to callback when exception occurs', function (): void {
    $eventsBeforeError = [
        new StreamStartEvent('evt-1', 1640995200, 'claude-3', 'anthropic'),
        new TextDeltaEvent('evt-2', 1640995201, 'Hello', 'msg-456'),
    ];

    $exception = new RuntimeException('API connection failed', 500);

    $pendingRequest = Mockery::mock(PendingRequest::class);

    $collectedEventsFromCallback = null;
    $userCallback = function ($request, $events) use (&$collectedEventsFromCallback): void {
        $collectedEventsFromCallback = $events;
    };

    $adapter = new DataProtocolAdapter;
    $response = ($adapter)(
        createThrowingGenerator($eventsBeforeError, $exception),
        $pendingRequest,
        $userCallback
    );

    $streamCallback = $response->getCallback();

    $outputBuffer = fopen('php://memory', 'r+');
    ob_start(function ($buffer) use ($outputBuffer): string {
        fwrite($outputBuffer, $buffer);

        return '';
    });

    try {
        $streamCallback();
        ob_end_flush();

        // Verify callback was called with collected events
        expect($collectedEventsFromCallback)->not->toBeNull();
        expect($collectedEventsFromCallback)->toBeInstanceOf(Collection::class);

        // Should have 3 events: StreamStart, TextDelta, and ErrorEvent
        expect($collectedEventsFromCallback)->toHaveCount(3);

        // Get the last event which should be the ErrorEvent
        $errorEvent = $collectedEventsFromCallback->last();
        expect($errorEvent)->toBeInstanceOf(ErrorEvent::class);
        expect($errorEvent->errorType)->toBe(RuntimeException::class);
        expect($errorEvent->message)->toBe('API connection failed');
        expect($errorEvent->recoverable)->toBeFalse();
        expect($errorEvent->metadata['code'])->toBe(500);
    } finally {
        fclose($outputBuffer);
    }
});

it('formats artifact events using data- prefix convention', function (): void {
    $artifact = new Artifact(
        data: 'iVBORw0KGgo=',
        mimeType: 'image/png',
        metadata: ['width' => 512],
        id: 'artifact-123',
    );

    $events = [
        new ArtifactEvent('evt-1', 1640995200, $artifact, 'tool-call-456', 'generate_image', 'msg-789'),
    ];

    $adapter = new DataProtocolAdapter;
    $response = ($adapter)(createDataEventGenerator($events));
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

        // Verify data- prefix convention is used (not bare "artifact")
        expect($capturedOutput)->toContain('data: {"type":"data-artifact"');
        expect($capturedOutput)->not->toContain('"type":"artifact"');

        // Verify payload is nested under "data" key
        expect($capturedOutput)->toContain('"data":{');
        expect($capturedOutput)->toContain('"toolCallId":"tool-call-456"');
        expect($capturedOutput)->toContain('"toolName":"generate_image"');
        expect($capturedOutput)->toContain('"mimeType":"image\/png"');
        expect($capturedOutput)->toContain('"id":"artifact-123"');

        // Parse the JSON to verify structure
        $lines = explode("\n", trim($capturedOutput));
        $dataLines = array_filter($lines, fn (string $line): bool => str_starts_with($line, 'data: ') && $line !== 'data: [DONE]');
        $artifactLine = array_values($dataLines)[0];
        $json = json_decode(substr($artifactLine, 6), true);

        expect($json['type'])->toBe('data-artifact');
        expect($json['data']['toolCallId'])->toBe('tool-call-456');
        expect($json['data']['toolName'])->toBe('generate_image');
        expect($json['data']['artifact']['id'])->toBe('artifact-123');
        expect($json['data']['artifact']['mimeType'])->toBe('image/png');
        expect($json['data']['artifact']['data'])->toBe('iVBORw0KGgo=');
        expect($json['data']['artifact']['metadata'])->toBe(['width' => 512]);
    } finally {
        fclose($outputBuffer);
    }
});
