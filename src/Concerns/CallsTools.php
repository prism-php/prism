<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\MultipleItemsFoundException;
use Illuminate\Support\Str;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Telemetry\Events\ToolCallCompleted;
use Prism\Prism\Telemetry\Events\ToolCallStarted;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Throwable;

trait CallsTools
{
    /**
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @return ToolResult[]
     */
    protected function callTools(array $tools, array $toolCalls): array
    {
        return array_map(
            function (ToolCall $toolCall) use ($tools): ToolResult {
                $tool = $this->resolveTool($toolCall->name, $tools);

                // Emit telemetry event for tool call start
                $spanId = null;
                if (config('prism.telemetry.enabled', false)) {
                    $spanId = Str::uuid()->toString();
                    $parentSpanId = Context::get('prism.telemetry.current_span_id');
                    $rootSpanId = Context::get('prism.telemetry.root_span_id') ?? $spanId;

                    Context::add('prism.telemetry.current_span_id', $spanId);
                    Context::add('prism.telemetry.parent_span_id', $parentSpanId);

                    Event::dispatch(new ToolCallStarted(
                        spanId: $spanId,
                        toolCall: $toolCall,
                        context: [
                            'parent_span_id' => $parentSpanId,
                            'root_span_id' => $rootSpanId,
                        ]
                    ));
                }

                try {
                    $result = call_user_func_array(
                        $tool->handle(...),
                        $toolCall->arguments()
                    );

                    $toolResult = new ToolResult(
                        toolCallId: $toolCall->id,
                        toolCallResultId: $toolCall->resultId,
                        toolName: $toolCall->name,
                        args: $toolCall->arguments(),
                        result: $result,
                    );

                    // Emit telemetry event for tool call completion
                    if (config('prism.telemetry.enabled', false) && $spanId) {
                        $parentSpanId = Context::get('prism.telemetry.parent_span_id');
                        $rootSpanId = Context::get('prism.telemetry.root_span_id');

                        Event::dispatch(new ToolCallCompleted(
                            spanId: $spanId,
                            toolCall: $toolCall,
                            toolResult: $toolResult,
                            context: [
                                'parent_span_id' => $parentSpanId,
                                'root_span_id' => $rootSpanId,
                            ]
                        ));

                        Context::add('prism.telemetry.current_span_id', $parentSpanId);
                    }

                    return $toolResult;
                } catch (Throwable $e) {
                    // Restore context on error
                    if (config('prism.telemetry.enabled', false) && $spanId) {
                        $parentSpanId = Context::get('prism.telemetry.parent_span_id');
                        Context::add('prism.telemetry.current_span_id', $parentSpanId);
                    }

                    if ($e instanceof PrismException) {
                        throw $e;
                    }

                    throw PrismException::toolCallFailed($toolCall, $e);
                }

            },
            $toolCalls
        );
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
