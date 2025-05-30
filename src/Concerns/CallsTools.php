<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\MultipleItemsFoundException;
use Illuminate\Support\Str;
use Prism\Prism\Events\ToolCallCompleted;
use Prism\Prism\Events\ToolCallStarted;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Throwable;

trait CallsTools
{
    protected ?string $parentContextId = null;

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

                $contextId = Str::uuid()->toString();

                Event::dispatch(new ToolCallStarted(
                    contextId: $contextId,
                    toolName: $toolCall->name,
                    parameters: $toolCall->arguments(),
                    parentContextId: $this->parentContextId,
                    attributes: [
                        'tool_call_id' => $toolCall->id,
                        'argument_count' => count($toolCall->arguments()),
                    ]
                ));

                try {
                    $result = call_user_func_array(
                        $tool->handle(...),
                        $toolCall->arguments()
                    );

                    Event::dispatch(new ToolCallCompleted(
                        contextId: $contextId,
                        attributes: [
                            'success' => true,
                            'result_length' => is_string($result) ? strlen($result) : null,
                        ]
                    ));

                    return new ToolResult(
                        toolCallId: $toolCall->id,
                        toolName: $toolCall->name,
                        args: $toolCall->arguments(),
                        result: $result,
                    );
                } catch (Throwable $e) {
                    Event::dispatch(new ToolCallCompleted(
                        contextId: $contextId,
                        exception: $e,
                        attributes: [
                            'success' => false,
                            'error_type' => $e::class,
                        ]
                    ));

                    if ($e instanceof PrismException) {
                        throw $e;
                    }

                    throw PrismException::toolCallFailed($toolCall, $e);
                }

            },
            $toolCalls
        );
    }

    protected function setParentContextId(?string $contextId): void
    {
        $this->parentContextId = $contextId;
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
