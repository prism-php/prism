<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\StreamCollector;
use Prism\Prism\Streaming\StreamedMessages;
use Prism\Prism\Text\Response;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;

it('keeps the native pending call and approval when a streamed step stops', function (?FinishReason $finish): void {
    $call = new ToolCall('call-pending', 'read_file', ['path' => 'résumé.txt']);
    $events = [new StepStartEvent('start', 1), new ToolCallEvent('call', 2, $call, 'message'),
        new ToolApprovalRequestEvent('approval', 3, $call, 'message', 'approval-pending')];
    if ($finish instanceof FinishReason) {
        $events[] = new StepFinishEvent('finish', 4, new Usage(3, 2), finishReason: $finish);
        $events[] = new StreamEndEvent('end', 5, $finish, usage: new Usage(3, 2));
    }
    $messages = StreamedMessages::fromEvents($events);
    expect($messages)->toHaveCount(1);
    $message = $messages->sole();
    expect($message)->toBeInstanceOf(AssistantMessage::class)
        ->toolCalls->toBe([$call])
        ->toolApprovalRequests->toHaveCount(1)
        ->and($message->toolApprovalRequests[0]->approvalId)->toBe('approval-pending')
        ->and($message->toolApprovalRequests[0]->toolCallId)->toBe('call-pending');
})->with(['paused' => [FinishReason::Pause], 'tool-calls' => [FinishReason::ToolCalls], 'interrupted' => [null]]);

it('records a pending call carried only by its approval event without inventing a result', function (): void {
    $call = new ToolCall('call-only', 'read_file', ['path' => 'one']);
    $messages = StreamedMessages::fromEvents([
        new ToolApprovalRequestEvent('approval', 1, $call, 'message', 'approval-only'),
        new StreamEndEvent('end', 2, FinishReason::Pause),
    ]);
    expect($messages)->toHaveCount(1)
        ->and($messages->sole())->toBeInstanceOf(AssistantMessage::class)
        ->toolCalls->toBe([$call])
        ->toolApprovalRequests->toHaveCount(1);
});

it('keeps multiple native approvals ordered without duplicating their call events', function (): void {
    $first = new ToolCall('first', 'read_file', ['path' => 'one']);
    $second = new ToolCall('second', 'read_file', ['path' => 'two']);
    $messages = StreamedMessages::fromEvents([
        new ToolCallEvent('call-1', 1, $first, 'message'),
        new ToolCallEvent('call-2', 2, $second, 'message'),
        new ToolApprovalRequestEvent('approval-1', 3, $first, 'message', 'pending-first'),
        new ToolApprovalRequestEvent('approval-2', 4, $second, 'message', 'pending-second'),
        new StepFinishEvent('finish', 5, finishReason: FinishReason::Pause),
    ]);
    expect($messages)->toHaveCount(1);
    $message = $messages->sole();
    expect($message->toolCalls)->toBe([$first, $second])
        ->and(array_map(fn (ToolApprovalRequest $request): string => $request->approvalId, $message->toolApprovalRequests))
        ->toBe(['pending-first', 'pending-second']);
});

it('does not preserve an unrelated unanswered call alongside an explicit pending approval', function (): void {
    $pending = new ToolCall('pending', 'read_file', ['path' => 'one']);
    $unanswered = new ToolCall('unanswered', 'read_file', ['path' => 'two']);
    $messages = StreamedMessages::fromEvents([
        new ToolCallEvent('unanswered', 1, $unanswered, 'message'),
        new ToolApprovalRequestEvent('approval', 2, $pending, 'message', 'pending-id'),
        new StepFinishEvent('finish', 3, finishReason: FinishReason::Pause),
    ]);
    expect($messages)->toHaveCount(1);
    $message = $messages->sole();
    expect($message->toolCalls)->toBe([$pending])
        ->and($message->toolApprovalRequests[0]->toolCallId)->toBe('pending');
});

it('does not carry an approval into another streamed step', function (): void {
    $call = new ToolCall('pending', 'read_file', []);
    $messages = StreamedMessages::fromEvents([
        new StepStartEvent('start-1', 1),
        new ToolApprovalRequestEvent('approval', 2, $call, 'message-1', 'pending-id'),
        new StepFinishEvent('finish-1', 3, finishReason: FinishReason::Pause),
        new StepStartEvent('start-2', 4),
        new TextDeltaEvent('text', 5, 'A second step', 'message-2'),
        new StepFinishEvent('finish-2', 6, finishReason: FinishReason::Stop),
    ]);
    expect($messages)->toHaveCount(2)
        ->and($messages[0]->toolApprovalRequests)->toHaveCount(1)
        ->and($messages[1]->toolApprovalRequests)->toBe([])
        ->and($messages[1]->toolCalls)->toBe([]);
});

it('reports the same pending approval through collector messages and native response steps', function (): void {
    $call = new ToolCall('pending', 'read_file', []);
    $events = [new ToolApprovalRequestEvent('approval', 1, $call, 'message', 'pending-id'),
        new StreamEndEvent('end', 2, FinishReason::Pause, usage: new Usage(3, 2))];
    $source = (function () use ($events): Generator {
        yield from $events;
    })();
    $messages = $response = null;
    $collector = new StreamCollector($source, null, function ($request, Collection $collected, Response $result) use (&$messages, &$response): void {
        $messages = $collected;
        $response = $result;
    });
    expect(iterator_to_array($collector->collect(), false))->toBe($events);
    expect($messages)->toHaveCount(1)
        ->and($messages->sole()->toolApprovalRequests)->toHaveCount(1)
        ->and($messages->sole()->toolApprovalRequests[0]->approvalId)->toBe('pending-id')
        ->and($response->steps)->toHaveCount(1)
        ->and($response->steps->sole()->toolApprovalRequests)->toBe($messages->sole()->toolApprovalRequests)
        ->and($response->toolResults)->toBe([]);
});

it('persists a real provider gate without executing it and resumes only an affirmative decision', function (bool $approved, int $expectedCalls): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'synthetic-offline');
    $sse = file_get_contents(__DIR__.'/../Fixtures/anthropic/stream-with-approval-tool-1.sse');
    $answer = file_get_contents(__DIR__.'/../Fixtures/anthropic/text-with-approval-phase2-1.json');
    expect($sse)->toBeString()->and($answer)->toBeString();
    Http::fakeSequence()->push($sse, 200, ['Content-Type' => 'text/event-stream'])->push($answer);
    $ran = 0;
    $tool = (new Tool)->as('delete_file')->for('An inert approval test')->withStringParameter('path', 'Path')
        ->using(function (string $path) use (&$ran): string {
            $ran++;

            return 'An inert result';
        })->requiresApproval();
    $messages = $response = null;
    $collector = new StreamCollector(Prism::text()->using('anthropic', 'claude-3-5-sonnet-20240620')
        ->withTools([$tool])->withMaxSteps(3)->withPrompt('An inert test')->asStream(), null,
        function ($request, Collection $collected, Response $result) use (&$messages, &$response): void {
            $messages = $collected;
            $response = $result;
        });
    iterator_to_array($collector->collect());
    expect($ran)->toBe(0);
    $pendingMessages = $messages->filter(fn ($message): bool => $message instanceof AssistantMessage && $message->toolApprovalRequests !== []);
    expect($pendingMessages)->toHaveCount(1);
    $pending = $pendingMessages->sole();
    $approval = $pending->toolApprovalRequests[0];
    expect($response->steps)->toHaveCount(1)
        ->and($response->steps->last()->toolApprovalRequests)->toHaveCount(1);
    expect($response->steps->last()->toolApprovalRequests[0]->approvalId)->toBe($approval->approvalId);
    Prism::text()->using('anthropic', 'claude-3-5-sonnet-20240620')->withTools([$tool])->withMaxSteps(3)
        ->withMessages([...$messages->all(), new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($approval->approvalId, $approved)])])->asText();
    expect($ran)->toBe($expectedCalls);
    Http::assertSentCount(2);
})->with(['approved' => [true, 1], 'denied' => [false, 0]]);
