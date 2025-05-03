<?php

namespace Prism\Prism\Testing\Concerns;

use Generator;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Response as TextResponse;

trait CanGenerateFakeChunksFromTextResponses
{
    /** Default string length used when chunking strings for the fake stream. */
    protected int $fakeChunkSize = 5;

    /** Override the default chunk size used when splitting text. */
    public function withFakeChunkSize(int $chunkSize): self
    {
        $this->fakeChunkSize = max(1, $chunkSize);

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
        $fakeChunkSize = $this->fakeChunkSize;

        if ($response->steps->isNotEmpty()) {
            foreach ($response->steps as $step) {
                // Stream out the textual part of the step.
                yield from $this->convertStringToTextChunkGenerator($step->text, $fakeChunkSize);

                // Forward tool calls / results as separate chunks (empty text).
                if ($step->toolCalls) {
                    yield new Chunk(text: '', toolCalls: $step->toolCalls);
                }
                if ($step->toolResults) {
                    yield new Chunk(text: '', toolResults: $step->toolResults);
                }
            }
        } else {
            yield from $this->convertStringToTextChunkGenerator($response->text, $fakeChunkSize);
        }

        // Signal the end of the stream with the original finish reason.
        yield new Chunk(text: '', finishReason: $response->finishReason);
    }

    /**
     * Split a string into a sequence of {@link Chunk}s of roughly <$size> bytes.
     *
     * @return Generator<Chunk>
     */
    protected function convertStringToTextChunkGenerator(string $text, int $chunkSize): Generator
    {
        $length = strlen($text);

        for ($offset = 0; $offset < $length; $offset += $chunkSize) {
            $chunk = mb_substr($text, $offset, $chunkSize);

            if ($chunk === '') {
                continue;
            }

            yield new Chunk(text: $chunk);
        }
    }
}
