<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Processors;

use Generator;
use Illuminate\Http\Client\Response;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Providers\Anthropic\Concerns\ProcessesRateLimits;
use Prism\Prism\Providers\Anthropic\ValueObjects\StreamState;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;

class MessageStartProcessor
{
    use ProcessesRateLimits;

    /**
     * @param  array<string, mixed>  $chunk
     */
    public function process(array $chunk, Response $response, StreamState $state): Chunk
    {
        $state
            ->setModel(data_get($chunk, 'message.model', ''))
            ->setRequestId(data_get($chunk, 'message.id', ''))
            ->setUsage(data_get($chunk, 'message.usage', []));

        return new Chunk(
            text: '',
            finishReason: null,
            meta: new Meta(
                id: $state->requestId(),
                model: $state->model(),
                rateLimits: $this->processRateLimits($response)
            ),
            chunkType: ChunkType::Meta
        );
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    public function processDelta(array $chunk, Request $request, StreamState $state, int $depth): ?Generator
    {
        $stopReason = data_get($chunk, 'delta.stop_reason', '');

        if (! empty($stopReason)) {
            $state->setStopReason($stopReason);
        }

        $usage = data_get($chunk, 'usage');

        if ($usage) {
            $state->setUsage($usage);
        }

        if ($state->isToolUseFinish()) {
            return $this->handleToolUseFinish($state);
        }

        return null;
    }

    protected function handleToolUseFinish(StreamState $state): Generator
    {
        $mappedToolCalls = $this->mapToolCalls($state);
        $additionalContent = $state->buildAdditionalContent();

        yield new Chunk(
            text: '',
            toolCalls: $mappedToolCalls,
            finishReason: null,
            additionalContent: $additionalContent
        );
    }

    /**
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(StreamState $state): array
    {
        return array_values(array_map(function (array $toolCall): ToolCall {
            $input = data_get($toolCall, 'input');
            if (is_string($input) && json_validate($input)) {
                $input = json_decode($input, true);
            }

            return new ToolCall(
                id: data_get($toolCall, 'id'),
                name: data_get($toolCall, 'name'),
                arguments: $input
            );
        }, $state->toolCalls()));
    }
}
