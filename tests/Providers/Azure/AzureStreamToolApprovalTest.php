<?php

declare(strict_types=1);

namespace Tests\Providers\Azure;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;

beforeEach(function (): void {
    config()->set('prism.providers.azure.url', 'https://test-resource.openai.azure.com');
    config()->set('prism.providers.azure.api_key', 'azure-key-1234');
    config()->set('prism.providers.azure.api_version', '2024-10-21');
    config()->set('prism.providers.azure.deployment_name', 'gpt-4o');
});

/**
 * One streamed chat-completions turn in which the model calls a single tool.
 */
function azureToolCallSse(string $id, string $name): string
{
    $chunk = fn (array $delta, ?string $finish = null): string => 'data: '.json_encode([
        'id' => 'chatcmpl-azure-hitl',
        'object' => 'chat.completion.chunk',
        'model' => 'gpt-4o',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]],
    ])."\n\n";

    return $chunk(['role' => 'assistant', 'tool_calls' => [[
        'index' => 0, 'id' => $id, 'type' => 'function',
        'function' => ['name' => $name, 'arguments' => '{"path":"a.txt"}'],
    ]]])
        .$chunk([], 'tool_calls')
        .'data: [DONE]'."\n\n";
}

it('never runs an approval-required tool while streaming, and asks for approval instead', function (): void {
    Http::fake([
        '*' => Http::response(azureToolCallSse('call_1', 'delete_file'), 200, ['Content-Type' => 'text/event-stream']),
    ])->preventStrayRequests();

    $ran = 0;

    $events = collect(iterator_to_array(Prism::text()
        ->using(Provider::Azure, 'gpt-4o')
        ->withTools([
            Tool::as('delete_file')->for('Delete a file')->withStringParameter('path', 'Path')
                ->using(function (string $path) use (&$ran): string {
                    $ran++;

                    return 'deleted';
                })
                ->requiresApproval(),
        ])
        ->withMaxSteps(4)
        ->withPrompt('Delete a.txt.')
        ->asStream(), false));

    expect($ran)->toBe(0)
        ->and($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty()
        ->and($events->whereInstanceOf(ToolApprovalRequestEvent::class)->sole()->toolCall->name)->toBe('delete_file')
        ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::ToolCalls);

    // The stream stops for the human: the tool result is never sent back.
    Http::assertSentCount(1);
});

/**
 * A conversation resumed after the stream stopped to ask about `delete_file`.
 *
 * @return array<int, mixed>
 */
function azureResumedConversation(bool $approved): array
{
    return [
        new UserMessage('Delete a.txt.'),
        new AssistantMessage(
            content: '',
            toolCalls: [new ToolCall(id: 'call_1', name: 'delete_file', arguments: ['path' => 'a.txt'])],
            additionalContent: [],
            toolApprovalRequests: [new ToolApprovalRequest(approvalId: 'approval-1', toolCallId: 'call_1')],
        ),
        new ToolResultMessage([], [new ToolApprovalResponse(approvalId: 'approval-1', approved: $approved)]),
    ];
}

it('runs an approved tool exactly once when the stream resumes, and only then', function (bool $approved, int $runs): void {
    $done = 'data: '.json_encode([
        'id' => 'chatcmpl-azure-done', 'object' => 'chat.completion.chunk', 'model' => 'gpt-4o',
        'choices' => [['index' => 0, 'delta' => ['content' => 'Done.'], 'finish_reason' => 'stop']],
    ])."\n\n".'data: [DONE]'."\n\n";

    Http::fake(['*' => Http::response($done, 200, ['Content-Type' => 'text/event-stream'])])->preventStrayRequests();

    $ran = 0;

    $events = collect(iterator_to_array(Prism::text()
        ->using(Provider::Azure, 'gpt-4o')
        ->withTools([
            Tool::as('delete_file')->for('Delete a file')->withStringParameter('path', 'Path')
                ->using(function (string $path) use (&$ran): string {
                    $ran++;

                    return "deleted {$path}";
                })
                ->requiresApproval(),
        ])
        ->withMessages(azureResumedConversation($approved))
        ->asStream(), false));

    expect($ran)->toBe($runs)
        ->and($events->whereInstanceOf(ToolResultEvent::class)->sole()->toolResult->toolCallId)->toBe('call_1')
        ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::Stop);
})->with([
    'approved' => [true, 1],
    'denied' => [false, 0],
]);

it('never runs a client-executed tool on the server while streaming', function (): void {
    Http::fake([
        '*' => Http::response(azureToolCallSse('call_1', 'pick_file'), 200, ['Content-Type' => 'text/event-stream']),
    ])->preventStrayRequests();

    $events = collect(iterator_to_array(Prism::text()
        ->using(Provider::Azure, 'gpt-4o')
        ->withTools([
            Tool::as('pick_file')->for('Ask the user to pick a file')->withStringParameter('path', 'Path')->clientExecuted(),
        ])
        ->withMaxSteps(4)
        ->withPrompt('Pick a file.')
        ->asStream(), false));

    expect($events->whereInstanceOf(ToolResultEvent::class))->toBeEmpty()
        ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe(FinishReason::ToolCalls);

    Http::assertSentCount(1);
});
