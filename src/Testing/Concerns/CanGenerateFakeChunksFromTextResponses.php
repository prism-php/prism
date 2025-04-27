<?php

namespace Prism\Prism\Testing\Concerns;

use Generator;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Response as TextResponse;

trait CanGenerateFakeChunksFromTextResponses
{
    /** Default byte length used when chunking strings for the fake stream. */
    protected int $defaultChunkBytes = 5;

    /** Override the default chunk size used when splitting text. */
    public function withChunkSize(int $bytes): self
    {
        $this->defaultChunkBytes = max(1, $bytes);

        return $this;
    }

    /**
     * Convert a {@link TextResponse} into a generator of {@link Chunk}s.
     *
     * The algorithm walks through the steps (if any) and yields:
     *  • text split into fixed-byte chunks,
     *  • an empty chunk carrying tool-calls / results when present,
     *  • finally an empty chunk with the original finish-reason.
     *
     * @return Generator<Chunk>
     */
    protected function chunksFromTextResponse(TextResponse $response): Generator
    {
        $chunkBytes = $this->defaultChunkBytes;

        if ($response->steps->isNotEmpty()) {
            foreach ($response->steps as $step) {
                // Stream out the textual part of the step.
                yield from $this->splitTextToTextChunkGenerator($step->text, $chunkBytes);

                // Forward tool calls / results as separate chunks (empty text).
                if ($step->toolCalls) {
                    yield new Chunk(text: '', toolCalls: $step->toolCalls);
                }
                if ($step->toolResults) {
                    yield new Chunk(text: '', toolResults: $step->toolResults);
                }
            }
        } else {
            yield from $this->splitTextToTextChunkGenerator($response->text, $chunkBytes);
        }

        // Signal the end of the stream with the original finish reason.
        yield new Chunk(text: '', finishReason: $response->finishReason);
    }

    /**
     * Split a string into a sequence of {@link Chunk}s of roughly <$size> bytes.
     *
     * @return Generator<Chunk>
     */
    protected function splitTextToTextChunkGenerator(string $text, int $size): Generator
    {
        $length = strlen($text);

        for ($offset = 0; $offset < $length; $offset += $size) {
            $piece = substr($text, $offset, $size);
            if ($piece === '') {
                continue;
            }

            yield new Chunk(text: $piece);
        }
    }
}
