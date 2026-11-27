<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Generator;
use Illuminate\Concurrency\ProcessDriver;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\MultipleItemsFoundException;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Support\ConcurrentToolFailure;
use Prism\Prism\Support\ToolResultClassifier;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Telemetry\TelemetryContext;
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
use RuntimeException;
use Throwable;

trait CallsTools
{
    /**
     * The denial a gated call receives when its step repeats a call id.
     */
    protected const REPEATED_CALL_ID_REASON = 'Tool call was not run because another call in this step has the same id';

    /**
     * The denial a gated call of an earlier step receives when a later step
     * with tool calls follows it before it has a result. Only the newest step
     * is resumed, so a decision can never reach it.
     */
    protected const REPLACED_STEP_REASON = 'Tool call was not run because a later step replaced it';

    /** @var ToolApprovalRequest[] */
    private array $stepApprovalRequests = [];

    /**
     * Execute tools and return results (for non-streaming handlers).
     *
     * This path has no way to stop for a human, so it refuses a call to a
     * client-executed or approval-required tool rather than running it. Azure
     * streaming called it and ran approval-required tools unasked; any handler
     * that can meet a marked tool must use callToolsWithPending() or
     * callToolsAndYieldEventsWithPending() and end the turn on ToolCalls.
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @return ToolResult[]
     *
     * @throws PrismException when a call targets a client-executed or approval-required tool
     */
    protected function callTools(array $tools, array $toolCalls): array
    {
        $hasPendingToolCalls = false;
        $approvalRequiredToolCalls = [];
        $this->filterServerExecutedToolCalls($tools, $toolCalls, $hasPendingToolCalls, $approvalRequiredToolCalls);

        if ($hasPendingToolCalls) {
            throw new PrismException('Refusing to run a client-executed or approval-required tool on a path that cannot pause for it; use callToolsWithPending().');
        }

        $toolResults = [];

        // Consume generator to execute all tools and collect results
        foreach ($this->callToolsAndYieldEvents($tools, $toolCalls, EventID::generate(), $toolResults) as $event) {
            // Events are discarded for non-streaming handlers
        }

        return $toolResults;
    }

    /**
     * Execute tools honoring client-executed and approval-required markers.
     *
     * Marked tool calls are NOT executed: $hasPendingToolCalls is set so the
     * handler stops its loop, and approval requests are collected into
     * $approvalRequests for correlation on resume.
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolApprovalRequest[]  $approvalRequests
     * @return ToolResult[]
     */
    protected function callToolsWithPending(array $tools, array $toolCalls, bool &$hasPendingToolCalls, array &$approvalRequests): array
    {
        $toolResults = [];

        foreach ($this->callToolsAndYieldEventsWithPending($tools, $toolCalls, EventID::generate(), $toolResults, $hasPendingToolCalls) as $event) {
            if ($event instanceof ToolApprovalRequestEvent) {
                $approvalRequests[] = $event->approvalRequest();
            }
        }

        $this->stepApprovalRequests = $approvalRequests;

        return $toolResults;
    }

    /**
     * The approval requests the last callToolsWithPending() issued, for the
     * Step the handler builds next. Taking them clears them, so a later step
     * of the same run cannot carry them again.
     *
     * A non-streaming response hands pending approvals back on its last step
     * (and, for text, on the assistant message the ResponseBuilder appends to
     * $response->messages). A handler that built its Step without them made
     * the documented resume find no request, so every approval was denied by
     * default and the approved tool never ran (G-352). Every non-streaming
     * handler that calls callToolsWithPending() passes this to its Step.
     *
     * @return ToolApprovalRequest[]
     */
    protected function approvalRequestsForStep(): array
    {
        $approvalRequests = $this->stepApprovalRequests;
        $this->stepApprovalRequests = [];

        return $approvalRequests;
    }

    /**
     * Generate tool execution events and collect results (for streaming handlers).
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults  Results are collected into this array by reference
     * @return Generator<ToolResultEvent|ArtifactEvent|ToolApprovalRequestEvent>
     */
    protected function callToolsAndYieldEvents(array $tools, array $toolCalls, string $messageId, array &$toolResults): Generator
    {
        $hasPendingToolCalls = false;

        yield from $this->yieldToolEvents($tools, $toolCalls, $messageId, $toolResults, $hasPendingToolCalls, filterPending: false);
    }

    /**
     * Streaming variant honoring client-executed and approval-required markers.
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults  Results are collected into this array by reference
     * @return Generator<ToolResultEvent|ArtifactEvent|ToolApprovalRequestEvent>
     */
    protected function callToolsAndYieldEventsWithPending(array $tools, array $toolCalls, string $messageId, array &$toolResults, bool &$hasPendingToolCalls): Generator
    {
        yield from $this->yieldToolEvents($tools, $toolCalls, $messageId, $toolResults, $hasPendingToolCalls, filterPending: true);
    }

    /**
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @return Generator<ToolResultEvent|ArtifactEvent|ToolApprovalRequestEvent>
     */
    protected function yieldToolEvents(array $tools, array $toolCalls, string $messageId, array &$toolResults, bool &$hasPendingToolCalls, bool $filterPending): Generator
    {
        $approvalRequiredToolCalls = [];

        if ($this->malformedCallWithholdsStep($tools, $toolCalls)) {
            yield from $this->refuseStep($toolCalls, $messageId, $toolResults);

            return;
        }

        $executableToolCalls = $filterPending
            ? $this->filterServerExecutedToolCalls($tools, $toolCalls, $hasPendingToolCalls, $approvalRequiredToolCalls)
            : $toolCalls;

        $groupedToolCalls = $this->groupToolCallsByConcurrency($tools, $executableToolCalls);

        $executionResults = $this->executeToolsWithConcurrency($tools, $groupedToolCalls, $messageId);

        // This tool batch belongs to the current step; advance the telemetry
        // step cursor so the next step's tools are tagged one step later.
        Telemetry::advanceStep(Telemetry::current());

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
                // Set here, where the call was parsed by this version, and
                // nowhere else.
                argumentsFormat: ToolApprovalRequest::ARGUMENTS_AS_SENT,
            );
        }
    }

    /**
     * Whether an unparseable call targets a tool that holds back its whole
     * step. It cannot be handed over, and its siblings must still not run.
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     */
    protected function malformedCallWithholdsStep(array $tools, array $toolCalls): bool
    {
        foreach ($toolCalls as $toolCall) {
            if (! $toolCall->hasMalformedArguments()) {
                continue;
            }

            try {
                if ($this->resolveTool($toolCall->name, $tools)->withholdsOtherCalls()) {
                    return true;
                }
            } catch (PrismException) {
                continue;
            }
        }

        return false;
    }

    /**
     * Report every call in the step as failed without running any of them.
     *
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @return Generator<ToolResultEvent|ArtifactEvent>
     */
    protected function refuseStep(array $toolCalls, string $messageId, array &$toolResults): Generator
    {
        $telemetryContext = Telemetry::current();

        foreach (array_values($toolCalls) as $index => $toolCall) {
            $failure = $toolCall->argumentsFailure()
                ?? new PrismException('Tool call was not run because another call in this step has invalid arguments');
            $result = self::failedToolExecution($toolCall, $failure, $messageId, microtime(true));

            if ($telemetryContext instanceof TelemetryContext) {
                $this->recordToolTelemetry($telemetryContext, $toolCall, $result, $index);
            }

            $toolResults[] = $result['toolResult'];

            yield from $result['events'];
        }

        Telemetry::advanceStep($telemetryContext);
    }

    /**
     * Split out client-executed and approval-required tool calls, setting the
     * pending flag when any are found.
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolCall[]  $approvalRequiredToolCalls  Collected by reference
     * @return array<int, ToolCall> Server-executed tool calls with original indices preserved
     */
    protected function filterServerExecutedToolCalls(array $tools, array $toolCalls, bool &$hasPendingToolCalls, array &$approvalRequiredToolCalls): array
    {
        $serverToolCalls = [];
        $withholdStep = false;

        foreach ($toolCalls as $index => $toolCall) {
            try {
                $tool = $this->resolveTool($toolCall->name, $tools);
            } catch (PrismException) {
                // Unknown tool — keep it so error handling fires in executeToolCall.
                $serverToolCalls[$index] = $toolCall;

                continue;
            }

            // Before every other check: unusable arguments reach no client, no
            // approval check and no handler. executeToolCall reports the failure.
            if ($toolCall->hasMalformedArguments()) {
                $serverToolCalls[$index] = $toolCall;

                continue;
            }

            if ($tool->isClientExecuted()) {
                $hasPendingToolCalls = true;
                $withholdStep = $withholdStep || $tool->withholdsOtherCalls();

                continue;
            }

            // Outside any catch: an approval check that fails stops the call,
            // and the step, before anything in it has run.
            if ($tool->needsApproval($toolCall->arguments())) {
                $hasPendingToolCalls = true;
                $approvalRequiredToolCalls[] = $toolCall;

                continue;
            }

            $serverToolCalls[$index] = $toolCall;
        }

        // A client-executed tool that withholds the step: nothing from this
        // step runs, not even a call that would only have asked for approval.
        if ($withholdStep) {
            $approvalRequiredToolCalls = [];

            return [];
        }

        return $serverToolCalls;
    }

    /**
     * Yield stream completion events when marked tool calls are pending: the
     * step and stream end with FinishReason::ToolCalls so the consumer can
     * collect the pending calls and resume.
     *
     * @return Generator<StepFinishEvent|StreamEndEvent>
     */
    protected function yieldToolCallsFinishEvents(StreamState $state): Generator
    {
        // The STEP's usage, not the running total. `$state->usage()` is the
        // whole turn so far and belongs on the StreamEndEvent below; handed to
        // a step it showed step N as costing steps 1..N together.
        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time(),
            usage: $state->takeStepUsage(),
        );

        yield new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: FinishReason::ToolCalls,
            usage: $state->usage(),
            citations: $state->citations() !== [] ? $state->citations() : null,
        );
    }

    /**
     * Resolve pending tool approvals from a previous request (non-streaming).
     *
     * Scans the request messages for a ToolResultMessage carrying approval
     * responses after the last tool-calling AssistantMessage. Approved tools
     * are executed; denied or unanswered ones produce denial results
     * (deny-by-default). The tool message is replaced with one containing the
     * merged results so the conversation is complete before the next send.
     */
    protected function resolveToolApprovals(StructuredRequest|TextRequest $request): void
    {
        foreach ($this->resolveToolApprovalsAndYieldEvents($request, EventID::generate()) as $event) {
            // Events are discarded for non-streaming handlers
        }
    }

    /**
     * @return Generator<ToolResultEvent|ArtifactEvent>
     */
    protected function resolveToolApprovalsAndYieldEvents(StructuredRequest|TextRequest $request, string $messageId): Generator
    {
        // Decisions apply to ONE step: the newest with tool calls. Every
        // earlier step's open gated calls are closed first, as denied.
        yield from $this->denyReplacedSteps($request, $messageId);

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

        // EVERY tool result message after the assistant, read as one. A stopped
        // step already carries a message with the results of the tools it did
        // run, and the decisions arrive in another one after it. Reading only
        // the first found the results and never the decisions, so an approved
        // call was denied by default.
        $toolMessageIndexes = [];
        $toolResultsSoFar = [];
        $approvalResponses = [];
        $messageCount = count($messages);

        for ($i = $assistantMessageIndex + 1; $i < $messageCount; $i++) {
            if ($messages[$i] instanceof ToolResultMessage) {
                $toolMessageIndexes[] = $i;
                $toolResultsSoFar = [...$toolResultsSoFar, ...$messages[$i]->toolResults];
                $approvalResponses = [...$approvalResponses, ...$messages[$i]->toolApprovalResponses];
            }
        }

        $toolMessage = new ToolResultMessage($toolResultsSoFar, $approvalResponses);

        // A decision reaches a call through its request's call id, so the id
        // must name exactly one call. A provider can give two calls in one
        // step the same id (Gemini uses the function name), and then a map
        // keyed by it hands one decision to both. Such a step is denied: no
        // gated call in it runs, whatever was decided.
        $repeatedCallIds = self::stepRepeatsCallIds($assistantMessage);

        $toolCallIdToApprovalId = [];
        $toolCallIdToApprovalRequest = [];
        foreach ($assistantMessage->toolApprovalRequests as $approvalRequest) {
            $toolCallIdToApprovalId[$approvalRequest->toolCallId] = $approvalRequest->approvalId;
            $toolCallIdToApprovalRequest[$approvalRequest->toolCallId] = $approvalRequest;
        }

        $approvalResolvedToolResults = [];
        $earlierCalls = [];

        foreach (array_slice($messages, 0, $assistantMessageIndex) as $message) {
            if ($message instanceof AssistantMessage) {
                $earlierCalls = [...$earlierCalls, ...$message->toolCalls];
            }
        }

        $callsWithOwnResult = self::callsWithOwnResult($assistantMessage->toolCalls, $toolMessage->toolResults, $earlierCalls);

        foreach ($assistantMessage->toolCalls as $index => $toolCall) {
            // A call that already has a result is DONE, approved or not. This
            // check used to run only for calls with no decision, so a call
            // approved on an earlier request ran again on every later request
            // whose history still carried the approval: a transfer approved
            // once executed once per turn after it.
            //
            // The result must be the call's OWN. Matched by id alone, the
            // result of an ungated call that already ran also marked a gated
            // call with the same id done, and that call got no outcome at all.
            if (isset($callsWithOwnResult[$index])) {
                continue;
            }

            if ($repeatedCallIds) {
                if ($toolsByName->get($toolCall->name)?->hasApprovalConfigured() !== true) {
                    continue;
                }

                $approval = new ToolApprovalResponse(EventID::generate('apr'), false, self::REPEATED_CALL_ID_REASON);
            } else {
                $approvalId = $toolCallIdToApprovalId[$toolCall->id] ?? null;
                $approval = $approvalId !== null ? $toolMessage->findByApprovalId($approvalId) : null;
            }

            if (! $approval instanceof ToolApprovalResponse) {
                if ($toolsByName->get($toolCall->name)?->hasApprovalConfigured() !== true) {
                    continue;
                }

                // Deny by default when no approval response was provided.
                $approval = new ToolApprovalResponse($approvalId ?? EventID::generate('apr'), false, 'No approval response provided');
            }

            if ($approval->approved) {
                $tool = $toolsByName->get($toolCall->name);
                $withoutArguments = new ToolCall($toolCall->id, $toolCall->name, [], $toolCall->resultId, $toolCall->reasoningId, $toolCall->reasoningSummary);
                $refusal = $tool instanceof Tool
                    ? self::storedArgumentsRefusal($tool, $toolCall, $toolCallIdToApprovalRequest[$toolCall->id] ?? null)
                    : null;

                // A refused call is recorded without its arguments, in the
                // result, the event and telemetry alike.
                if ($refusal !== null) {
                    $result = self::failedToolExecution($withoutArguments, $refusal['failure'], $messageId, microtime(true));

                    if ($refusal['kind'] !== null) {
                        $result['failure'] = $refusal['kind'];
                    }
                } else {
                    $result = $this->executeToolCall($request->tools(), $toolCall, $messageId);
                }

                $telemetryContext = Telemetry::current();

                if ($telemetryContext instanceof TelemetryContext) {
                    Telemetry::toolInvoked($telemetryContext, $refusal !== null ? $withoutArguments : $toolCall, $result['toolResult'], $result['durationMs'], failed: $result['failed'], failure: $result['failure']);
                }

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
                args: $toolCall->argumentsForResult(),
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

        if ($approvalResolvedToolResults === []) {
            return;
        }

        if ($toolMessageIndexes !== []) {
            $request->setMessages(array_values(array_filter(
                $messages,
                fn (int $index): bool => ! in_array($index, $toolMessageIndexes, true),
                ARRAY_FILTER_USE_KEY,
            )));
        }

        $request->addMessage(new ToolResultMessage(
            array_merge($toolMessage->toolResults, $approvalResolvedToolResults),
            $toolMessage->toolApprovalResponses,
        ));
    }

    /**
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
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
     * Give every gated call of an earlier step that has no outcome a denial,
     * once, placed with that step's results. A later step with tool calls
     * replaced it: only the newest step is resumed, so whatever was decided
     * for an earlier step is never applied, to it or to any other.
     *
     * A call's outcome is a result in its own step's segment, or, recorded
     * anywhere else, a result naming exactly that call (its id, name and
     * arguments) that no other call took. Such a result is never given to a
     * call of the step it was recorded after, so it answers one call only.
     *
     * @return Generator<ToolResultEvent>
     */
    protected function denyReplacedSteps(StructuredRequest|TextRequest $request, string $messageId): Generator
    {
        $messages = array_values($request->messages());
        $steps = array_keys(array_filter(
            $messages,
            fn (mixed $message): bool => $message instanceof AssistantMessage && $message->toolCalls !== [],
        ));

        if (count($steps) < 2) {
            return;
        }

        $toolsByName = collect($request->tools())->keyBy(fn (Tool $tool): string => $tool->name());
        $insertAfter = [];
        $changed = false;
        $segments = [];
        $owned = [];
        $stray = [];

        // Each step's own results: those in its segment, up to the next step.
        foreach ($steps as $position => $stepIndex) {
            /** @var AssistantMessage $step */
            $step = $messages[$stepIndex];
            $end = $steps[$position + 1] ?? count($messages);
            $segment = [];
            $results = [];
            $otherCalls = [];

            for ($i = $stepIndex + 1; $i < $end; $i++) {
                if ($messages[$i] instanceof ToolResultMessage) {
                    $segment[] = $i;
                    $results = [...$results, ...$messages[$i]->toolResults];
                }
            }

            foreach ($steps as $other) {
                if ($other !== $stepIndex) {
                    /** @var AssistantMessage $otherStep */
                    $otherStep = $messages[$other];
                    $otherCalls = [...$otherCalls, ...$otherStep->toolCalls];
                }
            }

            $pairing = self::pairResults($step->toolCalls, $results, $otherCalls);
            $segments[$stepIndex] = $segment;
            $owned[$stepIndex] = $pairing['owned'];

            foreach ($results as $at => $result) {
                if (! isset($pairing['used'][$at])) {
                    $stray[] = $result;
                }
            }
        }

        foreach (array_slice($steps, 0, -1) as $stepIndex) {
            /** @var AssistantMessage $step */
            $step = $messages[$stepIndex];
            $segment = $segments[$stepIndex];
            $denials = [];

            foreach ($step->toolCalls as $index => $toolCall) {
                if (isset($owned[$stepIndex][$index])) {
                    continue;
                }

                if ($toolsByName->get($toolCall->name)?->hasApprovalConfigured() !== true) {
                    continue;
                }

                // Answered by its own result recorded elsewhere: that is its
                // one outcome, so it is not also denied.
                foreach ($stray as $at => $result) {
                    if (self::isResultOf($toolCall, $result)) {
                        unset($stray[$at]);

                        continue 2;
                    }
                }

                $denial = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->argumentsForResult(),
                    result: self::REPLACED_STEP_REASON,
                    toolCallResultId: $toolCall->resultId,
                );

                $denials[] = $denial;

                yield new ToolResultEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    toolResult: $denial,
                    messageId: $messageId,
                    success: false,
                    error: self::REPLACED_STEP_REASON,
                );
            }

            if ($denials === []) {
                continue;
            }

            $changed = true;

            if ($segment !== []) {
                $last = $segment[array_key_last($segment)];
                /** @var ToolResultMessage $existing */
                $existing = $messages[$last];
                $messages[$last] = new ToolResultMessage([...$existing->toolResults, ...$denials], $existing->toolApprovalResponses);
            } else {
                $insertAfter[$stepIndex] = new ToolResultMessage($denials);
            }
        }

        if (! $changed) {
            return;
        }

        $rebuilt = [];

        foreach ($messages as $index => $message) {
            $rebuilt[] = $message;

            if (isset($insertAfter[$index])) {
                $rebuilt[] = $insertAfter[$index];
            }
        }

        $request->setMessages($rebuilt);
    }

    /**
     * The calls in a step that already have a result of their own, by index.
     *
     * A result belongs to ONE call. When ids are distinct this is the same as
     * matching by id. When they repeat, a result is given to a call with its
     * id, name and arguments first, then to one with its id and name, then to
     * one with its id, in the step's order, and each result to one call only.
     * Calls left with no identical twin to tell apart are interchangeable, so
     * the order cannot change what is recorded. More results than calls for an
     * id (one streamed call per id) still mark that call done. A result that
     * names exactly a call of another step ($otherCalls) is left to that call.
     *
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  ToolCall[]  $otherCalls
     * @return array<int, true>
     */
    protected static function callsWithOwnResult(array $toolCalls, array $toolResults, array $otherCalls = []): array
    {
        return self::pairResults($toolCalls, $toolResults, $otherCalls)['owned'];
    }

    /**
     * Pair a step's calls with the results recorded in its segment: which
     * calls have one (`owned`, by call index) and which results were taken
     * (`used`, by result index). A result that names exactly a call of
     * another step ($otherCalls) and no call of this one is that call's, so
     * the looser id-and-name and id-only matches never take it.
     *
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  ToolCall[]  $otherCalls
     * @return array{owned: array<int, true>, used: array<int, true>}
     */
    protected static function pairResults(array $toolCalls, array $toolResults, array $otherCalls = []): array
    {
        $toolResults = array_values($toolResults);
        $unused = $toolResults;
        $owned = [];

        $othersOnly = [];

        foreach ($toolResults as $at => $toolResult) {
            $names = fn (ToolCall $call): bool => self::isResultOf($call, $toolResult);

            if (array_filter($otherCalls, $names) !== [] && array_filter($toolCalls, $names) === []) {
                $othersOnly[$at] = true;
            }
        }

        $matchers = [
            fn (ToolCall $call, ToolResult $result, int $at): bool => self::isResultOf($call, $result),
            fn (ToolCall $call, ToolResult $result, int $at): bool => ! isset($othersOnly[$at]) && $result->toolCallId === $call->id && $result->toolName === $call->name,
            fn (ToolCall $call, ToolResult $result, int $at): bool => ! isset($othersOnly[$at]) && $result->toolCallId === $call->id,
        ];

        foreach ($matchers as $matches) {
            foreach ($toolCalls as $index => $toolCall) {
                if (isset($owned[$index])) {
                    continue;
                }

                foreach ($unused as $at => $toolResult) {
                    if ($matches($toolCall, $toolResult, $at)) {
                        $owned[$index] = true;
                        unset($unused[$at]);

                        break;
                    }
                }
            }
        }

        return ['owned' => $owned, 'used' => array_fill_keys(array_keys(array_diff_key($toolResults, $unused)), true)];
    }

    /**
     * Whether a result names exactly this call: its id, name and arguments.
     */
    protected static function isResultOf(ToolCall $toolCall, ToolResult $toolResult): bool
    {
        return $toolResult->toolCallId === $toolCall->id
            && $toolResult->toolName === $toolCall->name
            && self::sameArguments($toolResult->args, $toolCall->argumentsForResult());
    }

    /**
     * @param  array<mixed>  $left
     * @param  array<mixed>  $right
     */
    protected static function sameArguments(array $left, array $right): bool
    {
        return self::sortedArguments($left) === self::sortedArguments($right);
    }

    /**
     * @param  array<mixed>  $arguments
     * @return array<mixed>
     */
    protected static function sortedArguments(array $arguments): array
    {
        ksort($arguments);

        return array_map(fn (mixed $value): mixed => is_array($value) ? self::sortedArguments($value) : $value, $arguments);
    }

    /**
     * Whether the step's calls, or its approval requests, repeat a call id,
     * or its requests repeat an approval id. A repeated id cannot say which
     * call a decision belongs to, and one approval id would answer every
     * request that carries it.
     */
    protected static function stepRepeatsCallIds(AssistantMessage $assistantMessage): bool
    {
        $callIds = array_map(fn (ToolCall $toolCall): string => $toolCall->id, $assistantMessage->toolCalls);
        $requestCallIds = array_map(fn (ToolApprovalRequest $request): string => $request->toolCallId, $assistantMessage->toolApprovalRequests);
        $approvalIds = array_map(fn (ToolApprovalRequest $request): string => $request->approvalId, $assistantMessage->toolApprovalRequests);

        return count($callIds) !== count(array_unique($callIds, SORT_STRING))
            || count($requestCallIds) !== count(array_unique($requestCallIds, SORT_STRING))
            || count($approvalIds) !== count(array_unique($approvalIds, SORT_STRING));
    }

    /**
     * Why an approved stored call must not run, or null when it may.
     *
     * Before 0.152, arguments that could not be parsed were stored as one
     * string under `raw` or `input`. On a tool that does not name that key the
     * call is refused. On a tool that does, the stored call is the same as a
     * genuine one, so only the approval's marker can tell them apart: a
     * request without it was stored by an earlier version, and its approval
     * expires. The model is told to call again, and the new call is a new
     * approval for the person to decide.
     *
     * @return array{failure: PrismException, kind: ?string}|null
     */
    protected static function storedArgumentsRefusal(Tool $tool, ToolCall $toolCall, ?ToolApprovalRequest $approvalRequest): ?array
    {
        $key = self::storedWrappedKey($toolCall);

        if ($key === null) {
            return null;
        }

        if (! $tool->namesParameter($key)) {
            return ['failure' => PrismException::toolCallArgumentsNotDeclared($toolCall->name), 'kind' => null];
        }

        if ($approvalRequest?->argumentsAreAsSent() === true) {
            return null;
        }

        return ['failure' => PrismException::toolApprovalExpired($toolCall->name), 'kind' => 'approval_expired'];
    }

    /**
     * The key, when a stored call has the form earlier versions gave arguments
     * they could not parse: one string under `raw` or `input`.
     */
    protected static function storedWrappedKey(ToolCall $toolCall): ?string
    {
        if ($toolCall->hasMalformedArguments()) {
            return null;
        }

        $arguments = $toolCall->arguments();
        $key = array_key_first($arguments);

        return count($arguments) === 1
            && in_array($key, ['raw', 'input'], true)
            && is_string($arguments[$key])
            ? $key
            : null;
    }

    /**
     * @param  Tool[]  $tools
     * @param  array{concurrent: array<int, ToolCall>, sequential: array<int, ToolCall>}  $groupedToolCalls
     * @return array<int, array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>, durationMs: float, failed: bool, failure: ?string}>
     */
    protected function executeToolsWithConcurrency(array $tools, array $groupedToolCalls, string $messageId): array
    {
        $results = [];

        $concurrentClosures = [];

        $telemetryContext = Telemetry::current();

        $transportsNativeFailures = $groupedToolCalls['concurrent'] !== [] && Concurrency::driver() instanceof ProcessDriver;
        foreach ($groupedToolCalls['concurrent'] as $index => $toolCall) {
            $concurrentClosures[$index] = fn (): array|\Prism\Prism\Support\ConcurrentToolFailure => self::executeConcurrentToolCall($tools, $toolCall, $messageId, $transportsNativeFailures);
        }

        if ($concurrentClosures !== []) {
            $concurrentResults = Concurrency::run($concurrentClosures);
            if (count($concurrentResults) !== count($concurrentClosures) || array_diff_key($concurrentClosures, $concurrentResults) !== []) {
                throw new RuntimeException('Invalid concurrent tool result envelope.');
            }
            $firstFailure = null;
            foreach ($concurrentResults as $result) {
                self::validateConcurrentToolResult($result);
                if ($result instanceof ConcurrentToolFailure) {
                    $firstFailure ??= $result;
                }
            }
            try {
                foreach ($concurrentResults as $index => $result) {
                    try {
                        if ($result instanceof ConcurrentToolFailure) {
                            Telemetry::toolInvoked($telemetryContext, $groupedToolCalls['concurrent'][$index], null, $result->durationMs(), $index,
                                failed: true, failure: $result->failure());
                        } else {
                            $result = self::classifyToolExecution($groupedToolCalls['concurrent'][$index], $result);
                            $results[$index] = $result;
                            if ($telemetryContext instanceof TelemetryContext) {
                                $this->recordToolTelemetry($telemetryContext, $groupedToolCalls['concurrent'][$index], $result, $index);
                            }
                        }
                    } catch (Throwable $listenerFailure) {
                        if (! $firstFailure instanceof ConcurrentToolFailure) {
                            throw $listenerFailure;
                        }
                        // The native abort wins; still attempt each sibling's telemetry once.
                    }
                }
            } finally {
                if ($firstFailure instanceof ConcurrentToolFailure) {
                    $firstFailure->rethrow();
                }
            }
        }

        foreach ($groupedToolCalls['sequential'] as $index => $toolCall) {
            $result = $this->executeToolCall($tools, $toolCall, $messageId);

            $results[$index] = $result;

            if ($telemetryContext instanceof TelemetryContext) {
                $this->recordToolTelemetry($telemetryContext, $toolCall, $result, $index);
            }
        }

        return $results;
    }

    /**
     * Emit a ToolInvoked telemetry event for a single executed tool call.
     *
     * Reads defensively because concurrently-executed results arrive back from
     * Concurrency::run() as loosely-typed values.
     */
    protected function recordToolTelemetry(TelemetryContext $context, ToolCall $toolCall, mixed $result, int $index): void
    {
        if (! is_array($result)) {
            return;
        }

        $toolResult = $result['toolResult'] ?? null;

        if (! $toolResult instanceof ToolResult) {
            return;
        }

        $durationMs = $result['durationMs'] ?? 0.0;

        Telemetry::toolInvoked($context, $toolCall, $toolResult, is_float($durationMs) ? $durationMs : 0.0, $index,
            failed: ($result['failed'] ?? false) === true,
            failure: is_string($result['failure'] ?? null) ? $result['failure'] : null);
    }

    /**
     * @param  Tool[]  $tools
     * @return array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>, durationMs: float, failed: bool, failure: ?string}
     */
    protected static function executeToolCall(array $tools, ToolCall $toolCall, string $messageId, bool $deferClassification = false): array
    {
        $startedAt = microtime(true);
        try {
            $result = self::invokeToolCall($tools, $toolCall, $messageId);
        } catch (Throwable $failure) {
            try {
                Telemetry::toolInvoked(Telemetry::current(), $toolCall, null, (microtime(true) - $startedAt) * 1000,
                    failed: true, failure: self::toolExceptionKind($failure));
            } finally {
                // A listener failure must not replace the tool's original throwable.
                throw $failure;
            }
        }

        return $deferClassification ? $result : self::classifyToolExecution($toolCall, $result);
    }

    /**
     * @param  Tool[]  $tools
     * @return array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>, durationMs: float, failed: bool, failure: ?string}|ConcurrentToolFailure
     */
    private static function executeConcurrentToolCall(array $tools, ToolCall $call, string $messageId, bool $transportFailure): array|ConcurrentToolFailure
    {
        if (! $transportFailure) {
            return self::executeToolCall($tools, $call, $messageId, deferClassification: true);
        }
        $startedAt = microtime(true);
        try {
            return self::invokeToolCall($tools, $call, $messageId);
        } catch (Throwable $failure) {
            $durationMs = (microtime(true) - $startedAt) * 1000;
            // Preserve the reporting performed by Laravel's worker before returning its native payload.
            report($failure);

            return ConcurrentToolFailure::capture($failure, $durationMs);
        }
    }

    /** @phpstan-assert array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>, durationMs: float, failed: bool, failure: ?string}|ConcurrentToolFailure $result */
    private static function validateConcurrentToolResult(mixed $result): void
    {
        if ($result instanceof ConcurrentToolFailure) {
            $result->validate();

            return;
        }
        if (! is_array($result) || array_keys($result) !== ['toolResult', 'events', 'durationMs', 'failed', 'failure']
            || ! $result['toolResult'] instanceof ToolResult || ! is_array($result['events'])
            || ! is_float($result['durationMs']) || ! is_finite($result['durationMs'])
            || ! is_bool($result['failed'])
            || ($result['failed']
                ? (! is_string($result['failure']) || preg_match('/\A(?:tool_error|exception:[A-Za-z0-9_]{1,64})\z/D', $result['failure']) !== 1)
                : $result['failure'] !== null)) {
            throw new RuntimeException('Invalid concurrent tool result envelope.');
        }
        foreach ($result['events'] as $event) {
            if (! $event instanceof ToolResultEvent && ! $event instanceof ArtifactEvent) {
                throw new RuntimeException('Invalid concurrent tool result envelope.');
            }
        }
    }

    /**
     * Same post-tool value and parent ToolCall for every execution surface.
     *
     * @param  array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>, durationMs: float, failed: bool, failure: ?string}  $result
     * @return array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>, durationMs: float, failed: bool, failure: ?string}
     */
    private static function classifyToolExecution(ToolCall $call, array $result): array
    {
        if ($result['failed']) {
            return $result;
        }
        $failure = ToolResultClassifier::failure($call, $result['toolResult']->result);
        if ($failure === null) {
            return $result;
        }
        $result['failed'] = true;
        $result['failure'] = $failure;
        foreach ($result['events'] as $index => $event) {
            if ($event instanceof ToolResultEvent) {
                $result['events'][$index] = new ToolResultEvent($event->id, $event->timestamp, $event->toolResult, $event->messageId, false, $event->error);
            }
        }

        return $result;
    }

    private static function toolExceptionKind(Throwable $failure): string
    {
        $short = class_basename($failure);

        return 'exception:'.(preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $short) === 1 ? $short : 'unknown');
    }

    /**
     * @param  Tool[]  $tools
     * @return array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>, durationMs: float, failed: bool, failure: ?string}
     */
    private static function invokeToolCall(array $tools, ToolCall $toolCall, string $messageId): array
    {
        $events = [];

        $startedAt = microtime(true);

        try {
            // The one gate every execution path passes through.
            $argumentsFailure = $toolCall->argumentsFailure();

            if ($argumentsFailure instanceof PrismException) {
                throw $argumentsFailure;
            }

            $tool = self::resolveTool($toolCall->name, $tools);
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
                    'durationMs' => (microtime(true) - $startedAt) * 1000,
                    'failed' => true,
                    'failure' => 'tool_error',
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
                'durationMs' => (microtime(true) - $startedAt) * 1000,
                'failed' => false,
                'failure' => null,
            ];
        } catch (PrismException $e) {
            return self::failedToolExecution($toolCall, $e, $messageId, $startedAt);
        }
    }

    /**
     * @return array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>, durationMs: float, failed: bool, failure: ?string}
     */
    protected static function failedToolExecution(ToolCall $toolCall, PrismException $failure, string $messageId, float $startedAt): array
    {
        $toolResult = new ToolResult(
            toolCallId: $toolCall->id,
            toolName: $toolCall->name,
            args: $toolCall->argumentsForResult(),
            result: $failure->getMessage(),
            toolCallResultId: $toolCall->resultId,
        );

        return [
            'toolResult' => $toolResult,
            'events' => [new ToolResultEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolResult: $toolResult,
                messageId: $messageId,
                success: false,
                error: $failure->getMessage(),
            )],
            'durationMs' => (microtime(true) - $startedAt) * 1000,
            'failed' => true,
            'failure' => self::toolExceptionKind($failure),
        ];
    }

    /**
     * @param  Tool[]  $tools
     *
     * @throws PrismException
     */
    protected static function resolveTool(string $name, array $tools): Tool
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
