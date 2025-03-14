<?php

declare(strict_types=1);

namespace Prism\Prism\Contracts;

use Generator;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

interface Provider
{
    public function text(TextRequest $request): TextResponse;

    public function structured(StructuredRequest $request): StructuredResponse;

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse;

    /**
     * @return Generator<Chunk>
     */
    public function stream(TextRequest $request): Generator;
}
