<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\MultipleItemsFoundException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Throwable;

trait CallsTools
{
    /**
     * Execute tools, skipping deferred ones (HITL or client-executed).
     *
     * Deferred tools are not executed - the frontend will provide their results
     * after client-side execution.
     *
     * @param Tool[] $tools
     * @param ToolCall[] $toolCalls
     * @return array{results: ToolResult[], hasDeferred: bool}
     * @throws PrismException
     */
    protected function callTools(array $tools, array $toolCalls): array
    {
        $results = [];
        $hasDeferred = false;

        foreach ($toolCalls as $toolCall) {
                $tool = $this->resolveTool($toolCall->name, $tools);

                // Skip deferred tools - frontend will provide results
                if ($tool->isClientExecuted()) {
                    $hasDeferred = true;
                    continue;
                }

                try {
                    $result = call_user_func_array(
                        $tool->handle(...),
                        $toolCall->arguments()
                    );

                $results[] = new ToolResult(
                        toolCallId: $toolCall->id,
                        toolName: $toolCall->name,
                        args: $toolCall->arguments(),
                        result: $result,
                        toolCallResultId: $toolCall->resultId,
                    );
                } catch (Throwable $e) {
                    if ($e instanceof PrismException) {
                        throw $e;
                    }

                    throw PrismException::toolCallFailed($toolCall, $e);
                }
        }

        return ['results' => $results, 'hasDeferred' => $hasDeferred];
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
