<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Generator;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\MultipleItemsFoundException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolOutput;
use Prism\Prism\ValueObjects\ToolResult;

trait CallsTools
{
    /**
     * Execute tools and return results (for non-streaming handlers).
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @return ToolResult[]
     */
    protected function callTools(array $tools, array $toolCalls): array
    {
        $toolResults = [];

        // Consume generator to execute all tools and collect results
        foreach ($this->callToolsAndYieldEvents($tools, $toolCalls, EventID::generate(), $toolResults) as $event) {
            // Events are discarded for non-streaming handlers
        }

        return $toolResults;
    }

    /**
     * Generate tool execution events and collect results (for streaming handlers).
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults  Results are collected into this array by reference
     * @return Generator<ToolResultEvent|ArtifactEvent>
     */
    protected function callToolsAndYieldEvents(array $tools, array $toolCalls, string $messageId, array &$toolResults): Generator
    {
        $groupedToolCalls = $this->groupToolCallsByConcurrency($tools, $toolCalls);

        $executionResults = $this->executeToolsWithConcurrency($tools, $groupedToolCalls, $messageId);

        foreach (array_keys($toolCalls) as $index) {
            $result = $executionResults[$index];

            $toolResults[] = $result['toolResult'];

            foreach ($result['events'] as $event) {
                yield $event;
            }
        }
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
     * @param  Tool[]  $tools
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
