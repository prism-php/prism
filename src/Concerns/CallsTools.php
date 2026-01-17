<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Generator;
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
        foreach ($toolCalls as $toolCall) {
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

                $toolResults[] = $toolResult;

                yield new ToolResultEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    toolResult: $toolResult,
                    messageId: $messageId,
                    success: true
                );

                foreach ($toolResult->artifacts as $artifact) {
                    yield new ArtifactEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        artifact: $artifact,
                        toolCallId: $toolCall->id,
                        toolName: $toolCall->name,
                        messageId: $messageId,
                    );
                }
            } catch (PrismException $e) {
                $toolResult = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: $e->getMessage(),
                    toolCallResultId: $toolCall->resultId,
                );

                $toolResults[] = $toolResult;

                yield new ToolResultEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    toolResult: $toolResult,
                    messageId: $messageId,
                    success: false,
                    error: $e->getMessage()
                );
            }
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
