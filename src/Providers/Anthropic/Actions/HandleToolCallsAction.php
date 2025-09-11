<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Actions;

use Generator;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Throwable;

class HandleToolCallsAction
{
    use CallsTools;

    /**
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<string, mixed>|null  $additionalContent
     * @return Generator<Chunk>
     */
    public function __invoke(Request $request, array $toolCalls, ?array $additionalContent = null, string $text = ''): Generator
    {
        $toolResults = [];

        foreach ($toolCalls as $toolCall) {
            $tool = $this->resolveTool($toolCall->name, $request->tools());

            try {
                $result = call_user_func_array(
                    $tool->handle(...),
                    $toolCall->arguments()
                );

                $toolResult = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: $result,
                );

                $toolResults[] = $toolResult;

                yield new Chunk(
                    text: '',
                    toolResults: [$toolResult],
                    chunkType: ChunkType::ToolResult
                );
            } catch (Throwable $e) {
                if ($e instanceof PrismException) {
                    throw $e;
                }

                throw PrismException::toolCallFailed($toolCall, $e);
            }
        }

        $this->addMessagesToRequest($request, $toolResults, $additionalContent, $toolCalls, $text);
    }

    /**
     * @param  array<int|string, mixed>  $toolResults
     * @param  array<string, mixed>|null  $additionalContent
     * @param  array<int, ToolCall>  $toolCalls
     */
    protected function addMessagesToRequest(Request $request, array $toolResults, ?array $additionalContent, array $toolCalls, string $text): void
    {
        $request->addMessage(new AssistantMessage(
            content: $text,
            toolCalls: $toolCalls,
            additionalContent: $additionalContent ?? []
        ));

        $message = new ToolResultMessage($toolResults);

        $toolResultCacheType = $request->providerOptions('tool_result_cache_type');
        if ($toolResultCacheType) {
            $message->withProviderOptions(['cacheType' => $toolResultCacheType]);
        }

        $request->addMessage($message);
    }
}
