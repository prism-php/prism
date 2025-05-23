<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\MultipleItemsFoundException;
use Prism\Prism\Contracts\Telemetry;
use Prism\Prism\Exceptions\PrismException;
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
            fn (ToolCall $toolCall): ToolResult => $this->traceToolExecution($toolCall, function () use ($tools, $toolCall): ToolResult {
                $tool = $this->resolveTool($toolCall->name, $tools);

                try {
                    $result = call_user_func_array(
                        $tool->handle(...),
                        $toolCall->arguments()
                    );

                    return new ToolResult(
                        toolCallId: $toolCall->id,
                        toolName: $toolCall->name,
                        args: $toolCall->arguments(),
                        result: $result,
                    );
                } catch (Throwable $e) {
                    if ($e instanceof PrismException) {
                        throw $e;
                    }

                    throw PrismException::toolCallFailed($toolCall, $e);
                }
            }),
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

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function traceToolExecution(ToolCall $toolCall, callable $callback): mixed
    {
        $telemetry = app(Telemetry::class);

        return $telemetry->childSpan(
            "prism.tool.call.{$toolCall->name}",
            [
                'prism.tool.name' => $toolCall->name,
                'prism.tool.call_id' => $toolCall->id,
                'prism.tool.arg_count' => count($toolCall->arguments()),
            ],
            $callback
        );
    }
}
