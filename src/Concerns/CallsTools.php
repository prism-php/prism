<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\MultipleItemsFoundException;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
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
            fn(ToolCall $toolCall): ToolResult => $this->traceToolExecution($toolCall, function () use ($tools, $toolCall): ToolResult {
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
        if (! config('prism.telemetry.enabled', false)) {
            return $callback();
        }

        $tracer = app(TracerInterface::class);

        // Create child span that will automatically use current context as parent
        $span = $tracer->spanBuilder("prism.tool.call.{$toolCall->name}")
            ->setParent(Context::getCurrent())
            ->startSpan();

        $span->setAttribute('prism.tool.name', $toolCall->name);
        $span->setAttribute('prism.tool.call_id', $toolCall->id);
        $span->setAttribute('prism.tool.arg_count', count($toolCall->arguments()));

        try {
            $result = $callback();
            $span->setStatus(StatusCode::STATUS_OK);

            return $result;
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $span->end();
        }
    }
}
