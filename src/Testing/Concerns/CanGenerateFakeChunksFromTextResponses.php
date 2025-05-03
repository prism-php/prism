<?php

namespace Prism\Prism\Testing\Concerns;

use Generator;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\Step;

trait CanGenerateFakeChunksFromTextResponses
{
    /** Default string length used when chunking strings for the fake stream. */
    protected int $fakeChunkSize = 5;

    /** Override the default chunk size used when generating fake chunks. */
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
                yield from $this->convertStringToTextChunkGenerator($step->text, $fakeChunkSize);

                if ($toolCallsOrResultsChunk = $this->getToolChunkFromStepIfItExists($step)) {
                    yield $toolCallsOrResultsChunk;
                }
            }
        } else {
            yield from $this->convertStringToTextChunkGenerator($response->text, $fakeChunkSize);
        }

        yield new Chunk(text: '', finishReason: $response->finishReason);
    }

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

    private function getToolChunkFromStepIfItExists(Step $step): ?Chunk
    {
        if ($step->toolCalls) {
            return new Chunk(text: '', toolCalls: $step->toolCalls);
        }

        if ($step->toolResults) {
            return new Chunk(text: '', toolResults: $step->toolResults);
        }

        return null;
    }
}
