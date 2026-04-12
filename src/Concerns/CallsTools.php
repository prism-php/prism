<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Generator;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\MultipleItemsFoundException;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use Prism\Prism\ValueObjects\ToolResult;

trait CallsTools
{
    /**
     * Execute tools and return results (for non-streaming handlers).
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolApprovalRequest[]  $approvalRequests  Tool calls requiring approval (collected by reference)
     * @return ToolResult[]
     */
    protected function callTools(array $tools, array $toolCalls, bool &$hasPendingToolCalls, array &$approvalRequests = []): array
    {
        $toolResults = [];

        foreach ($this->callToolsAndYieldEvents($tools, $toolCalls, EventID::generate(), $toolResults, $hasPendingToolCalls) as $event) {
            if ($event instanceof ToolApprovalRequestEvent) {
                $approvalRequests[] = new ToolApprovalRequest(
                    approvalId: $event->approvalId,
                    toolCallId: $event->toolCall->id,
                );
            }
        }

        return $toolResults;
    }

    /**
     * Generate tool execution events and collect results (for streaming handlers).
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults  Results are collected into this array by reference
     * @return Generator<ToolResultEvent|ArtifactEvent|ToolApprovalRequestEvent>
     */
    protected function callToolsAndYieldEvents(array $tools, array $toolCalls, string $messageId, array &$toolResults, bool &$hasPendingToolCalls): Generator
    {
        $approvalRequiredToolCalls = [];
        $serverToolCalls = $this->filterServerExecutedToolCalls($tools, $toolCalls, $hasPendingToolCalls, $approvalRequiredToolCalls);

        $groupedToolCalls = $this->groupToolCallsByConcurrency($tools, $serverToolCalls);

        $executionResults = $this->executeToolsWithConcurrency($tools, $groupedToolCalls, $messageId);

        foreach (collect($executionResults)->keys()->sort() as $index) {
            $result = $executionResults[$index];

            $toolResults[] = $result['toolResult'];

            foreach ($result['events'] as $event) {
                yield $event;
            }
        }

        foreach ($approvalRequiredToolCalls as $toolCall) {
            yield new ToolApprovalRequestEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolCall: $toolCall,
                messageId: $messageId,
                approvalId: EventID::generate('apr'),
            );
        }
    }

    /**
     * Filter out client-executed and approval-required tool calls, setting the pending flag if any are found.
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolCall[]  $approvalRequiredToolCalls  Collected approval-required tool calls (by reference)
     * @return array<int, ToolCall> Server-executed tool calls with original indices preserved
     */
    protected function filterServerExecutedToolCalls(array $tools, array $toolCalls, bool &$hasPendingToolCalls, array &$approvalRequiredToolCalls = []): array
    {
        $serverToolCalls = [];

        foreach ($toolCalls as $index => $toolCall) {
            try {
                $tool = $this->resolveTool($toolCall->name, $tools);

                if ($tool->isClientExecuted()) {
                    $hasPendingToolCalls = true;

                    continue;
                }

                if ($tool->needsApproval($toolCall->arguments())) {
                    $hasPendingToolCalls = true;
                    $approvalRequiredToolCalls[] = $toolCall;

                    continue;
                }

                $serverToolCalls[$index] = $toolCall;
            } catch (PrismException) {
                // Unknown tool - keep it so error handling works in executeToolCall
                $serverToolCalls[$index] = $toolCall;
            }
        }

        return $serverToolCalls;
    }

    /**
     * @param  Tool[]  $tools
     * @param  array<int, ToolCall>  $toolCalls
     * @return array{concurrent: array<int, ToolCall>, sequential: array<int, ToolCall>}
     */
    protected function groupToolCallsByConcurrency(array $tools, array $toolCalls): array
    {
        $concurrent = [];
        $sequential = [];

        foreach ($toolCalls as $index => $toolCall) {
            try {
                $tool = $this->resolveTool($toolCall->name, $tools);

                if ($tool->isConcurrent()) {
                    $concurrent[$index] = $toolCall;
                } else {
                    $sequential[$index] = $toolCall;
                }
            } catch (PrismException) {
                $sequential[$index] = $toolCall;
            }
        }

        return [
            'concurrent' => $concurrent,
            'sequential' => $sequential,
        ];
    }

    /**
     * @param  Tool[]  $tools
     * @param  array{concurrent: array<int, ToolCall>, sequential: array<int, ToolCall>}  $groupedToolCalls
     * @return array<int, array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>}>
     */
    protected function executeToolsWithConcurrency(array $tools, array $groupedToolCalls, string $messageId): array
    {
        $results = [];

        $concurrentClosures = [];

        foreach ($groupedToolCalls['concurrent'] as $index => $toolCall) {
            $concurrentClosures[$index] = fn () => $this->executeToolCall($tools, $toolCall, $messageId);
        }

        if ($concurrentClosures !== []) {
            foreach (Concurrency::run($concurrentClosures) as $index => $result) {
                $results[$index] = $result;
            }
        }

        foreach ($groupedToolCalls['sequential'] as $index => $toolCall) {
            $results[$index] = $this->executeToolCall($tools, $toolCall, $messageId);
        }

        return $results;
    }

    /**
     * @param  Tool[]  $tools
     * @return array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>}
     */
    protected function executeToolCall(array $tools, ToolCall $toolCall, string $messageId): array
    {
        $events = [];

        try {
            $tool = $this->resolveTool($toolCall->name, $tools);
            $output = call_user_func_array(
                $tool->handle(...),
                $toolCall->arguments()
            );

            if ($output instanceof ToolError) {
                $toolResult = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: $output->message,
                    toolCallResultId: $toolCall->resultId,
                );

                $events[] = new ToolResultEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    toolResult: $toolResult,
                    messageId: $messageId,
                    success: false,
                    error: $output->message,
                );

                return [
                    'toolResult' => $toolResult,
                    'events' => $events,
                ];
            }

            if (is_string($output)) {
                $output = new ToolOutput(result: $output);
            }

            $toolResult = new ToolResult(
                toolCallId: $toolCall->id,
                toolName: $toolCall->name,
                args: $toolCall->arguments(),
                result: $output->result,
                toolCallResultId: $toolCall->resultId,
                artifacts: $output->artifacts,
            );

            $events[] = new ToolResultEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolResult: $toolResult,
                messageId: $messageId,
                success: true
            );

            foreach ($toolResult->artifacts as $artifact) {
                $events[] = new ArtifactEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    artifact: $artifact,
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    messageId: $messageId,
                );
            }

            return [
                'toolResult' => $toolResult,
                'events' => $events,
            ];
        } catch (PrismException $e) {
            $toolResult = new ToolResult(
                toolCallId: $toolCall->id,
                toolName: $toolCall->name,
                args: $toolCall->arguments(),
                result: $e->getMessage(),
                toolCallResultId: $toolCall->resultId,
            );

            $events[] = new ToolResultEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolResult: $toolResult,
                messageId: $messageId,
                success: false,
                error: $e->getMessage()
            );

            return [
                'toolResult' => $toolResult,
                'events' => $events,
            ];
        }
    }

    /**
     * Yield stream completion events when client-executed tools are pending.
     *
     * @return Generator<StepFinishEvent|StreamEndEvent>
     */
    protected function yieldToolCallsFinishEvents(StreamState $state): Generator
    {
        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time()
        );

        yield new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: FinishReason::ToolCalls,
            usage: $state->usage(),
            citations: $state->citations(),
        );
    }

    /**
     * Resolve pending tool approvals from a previous request (non-streaming).
     *
     * Scans request messages for a ToolResultMessage with toolApprovalResponses after
     * the last AssistantMessage. If found, executes approved tools, creates denial
     * results for denied tools, and replaces it with a ToolResultMessage containing
     * merged tool results (existing + resolved) and the consumed approval responses.
     */
    protected function resolveToolApprovals(StructuredRequest|TextRequest $request): void
    {
        foreach ($this->resolveToolApprovalsAndYieldEvents($request, EventID::generate()) as $event) {
            // Events are discarded for non-streaming handlers
        }
    }

    /**
     * @return Generator<StreamStartEvent|ToolResultEvent|ArtifactEvent>
     */
    protected function resolveToolApprovalsAndYieldEvents(StructuredRequest|TextRequest $request, string $messageId, ?StreamState $state = null): Generator
    {
        $messages = $request->messages();

        $assistantMessage = null;
        $assistantMessageIndex = null;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof AssistantMessage && $messages[$i]->toolCalls !== []) {
                $assistantMessage = $messages[$i];
                $assistantMessageIndex = $i;

                break;
            }
        }

        if (! $assistantMessage instanceof AssistantMessage || $assistantMessageIndex === null) {
            return;
        }

        $toolsByName = collect($request->tools())->keyBy(fn (Tool $tool): string => $tool->name());
        $isAnyToolApprovalConfigured = collect($assistantMessage->toolCalls)->contains(
            fn (ToolCall $toolCall): bool => $toolsByName->get($toolCall->name)?->hasApprovalConfigured() === true,
        );

        if (! $isAnyToolApprovalConfigured) {
            return;
        }

        $toolMessage = null;
        $toolMessageIndex = null;
        $counter = count($messages);

        for ($i = $assistantMessageIndex + 1; $i < $counter; $i++) {
            if ($messages[$i] instanceof ToolResultMessage) {
                $toolMessage = $messages[$i];
                $toolMessageIndex = $i;

                break;
            }
        }

        if (! $toolMessage instanceof ToolResultMessage) {
            $toolMessage = new ToolResultMessage;
            $toolMessageIndex = null;
        }

        $toolCallIdToApprovalId = [];
        foreach ($assistantMessage->toolApprovalRequests as $approvalRequest) {
            $toolCallIdToApprovalId[$approvalRequest->toolCallId] = $approvalRequest->approvalId;
        }

        $approvalResolvedToolResults = [];

        foreach ($assistantMessage->toolCalls as $toolCall) {
            $approvalId = $toolCallIdToApprovalId[$toolCall->id] ?? null;
            $approval = $approvalId !== null ? $toolMessage->findByApprovalId($approvalId) : null;

            if (! $approval instanceof ToolApprovalResponse) {
                if (collect($toolMessage->toolResults)->contains(fn (ToolResult $tr): bool => $tr->toolCallId === $toolCall->id)) { // tool already executed
                    continue;
                }
                if ($toolsByName->get($toolCall->name)?->hasApprovalConfigured() !== true) {
                    continue;
                }

                $approval = new ToolApprovalResponse($approvalId ?? EventID::generate('apr'), false, 'No approval response provided');
            }

            if ($state instanceof StreamState && $state->shouldEmitStreamStart()) {
                yield new StreamStartEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    model: $request->model(),
                    provider: $request->provider(),
                );

                $state->markStreamStarted();
            }

            if ($approval->approved) {
                $result = $this->executeToolCall($request->tools(), $toolCall, $messageId);

                $approvalResolvedToolResults[] = $result['toolResult'];

                foreach ($result['events'] as $event) {
                    yield $event;
                }

                continue;
            }

            $reason = $approval->reason ?? 'User denied tool execution';

            $toolResult = new ToolResult(
                toolCallId: $toolCall->id,
                toolName: $toolCall->name,
                args: $toolCall->arguments(),
                result: $reason,
                toolCallResultId: $toolCall->resultId,
            );

            $approvalResolvedToolResults[] = $toolResult;

            yield new ToolResultEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolResult: $toolResult,
                messageId: $messageId,
                success: false,
                error: $reason,
            );
        }

        if ($toolMessageIndex !== null) { // remove old tool result message
            $updatedMessages = array_values(array_filter(
                $messages,
                fn (int $index): bool => $index !== $toolMessageIndex,
                ARRAY_FILTER_USE_KEY,
            ));
            $request->setMessages($updatedMessages);
        }

        // Add new tool result message which also contains results of approval resolved tools
        $request->addMessage(new ToolResultMessage(
            array_merge($toolMessage->toolResults, $approvalResolvedToolResults),
            $toolMessage->toolApprovalResponses
        ));
    }

    /**
     * @param  Tool[]  $tools
     *
     * @throws PrismException
     */
    protected function resolveTool(string $name, array $tools): Tool
    {
        try {
            return collect($tools)
                ->sole(fn (Tool $tool): bool => $tool->name() === $name);
        } catch (ItemNotFoundException $e) {
            throw PrismException::toolNotFound($name, $e);
        } catch (MultipleItemsFoundException $e) {
            throw PrismException::multipleToolsFound($name, $e);
        }
    }
}
