<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Processors;

use Illuminate\Http\Client\Response;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Providers\Anthropic\Concerns\ProcessesRateLimits;
use Prism\Prism\Providers\Anthropic\Maps\FinishReasonMap;
use Prism\Prism\Providers\Anthropic\ValueObjects\StreamState;
use Prism\Prism\Text\Chunk;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class MessageStopProcessor
{
    use ProcessesRateLimits;

    /**
     * @param  array<string, mixed>  $chunk
     */
    public function process(array $chunk, Response $response, StreamState $state): Chunk
    {
        $usage = $state->usage();

        return new Chunk(
            text: '',
            finishReason: FinishReasonMap::map($state->stopReason()),
            meta: new Meta(
                id: $state->requestId(),
                model: $state->model(),
                rateLimits: $this->processRateLimits($response)
            ),
            additionalContent: $state->buildAdditionalContent(),
            chunkType: ChunkType::Meta,
            usage: new Usage(
                promptTokens: $usage['input_tokens'] ?? 0,
                completionTokens: $usage['output_tokens'] ?? 0,
                cacheWriteInputTokens: $usage['cache_creation_input_tokens'] ?? 0,
                cacheReadInputTokens: $usage['cache_read_input_tokens'] ?? 0,
                thoughtTokens: $usage['cache_read_input_tokens'] ?? 0,
            )
        );
    }
}
