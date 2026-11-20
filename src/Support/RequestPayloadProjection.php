<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

use Prism\Prism\Exceptions\PrismUnprojectableRequestException;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;

/** @internal */
class RequestPayloadProjection
{
    /**
     * Approval resolution can execute a tool before dispatch. Inspect only the
     * configured flag and existing results; never evaluate an approval callback.
     */
    public static function assertProjectable(TextRequest|StructuredRequest $request): void
    {
        $messages = $request->messages();
        $assistant = null;
        $assistantIndex = null;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof AssistantMessage && $messages[$i]->toolCalls !== []) {
                $assistant = $messages[$i];
                $assistantIndex = $i;
                break;
            }
        }
        if (! $assistant instanceof AssistantMessage || $assistantIndex === null) {
            return;
        }

        $tools = [];
        foreach ($request->tools() as $tool) {
            $tools[$tool->name()] = $tool;
        }
        $hasApproval = false;
        foreach ($assistant->toolCalls as $call) {
            $hasApproval = $hasApproval || ($tools[$call->name] ?? null)?->hasApprovalConfigured() === true;
        }
        if (! $hasApproval) {
            return;
        }

        $results = [];
        $decisions = [];
        foreach (array_slice($messages, $assistantIndex + 1) as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    $results[$result->toolCallId] = true;
                }
                foreach ($message->toolApprovalResponses as $decision) {
                    $decisions[$decision->approvalId] = true;
                }
            }
        }
        $approvalIds = [];
        foreach ($assistant->toolApprovalRequests as $approval) {
            $approvalIds[$approval->toolCallId] = $approval->approvalId;
        }
        foreach ($assistant->toolCalls as $call) {
            if (isset($results[$call->id])) {
                continue;
            }
            if (($tools[$call->name] ?? null)?->hasApprovalConfigured() === true
                || isset($decisions[$approvalIds[$call->id] ?? ''])) {
                throw new PrismUnprojectableRequestException;
            }
        }
    }
}
