<?php

declare(strict_types=1);

namespace Prism\Prism\Testing\Concerns;

use Generator;

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
     * @return Generator<object{text: string}>
     */
    protected function convertStringToTextChunkGenerator(string $text, int $chunkSize): Generator
    {
        $length = strlen($text);

        for ($offset = 0; $offset < $length; $offset += $chunkSize) {
            $chunk = mb_substr($text, $offset, $chunkSize);

            if ($chunk === '') {
                continue;
            }

            yield (object) ['text' => $chunk];
        }
    }
}
