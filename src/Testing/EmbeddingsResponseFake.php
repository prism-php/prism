<?php

namespace Prism\Prism\Testing;

use Prism\Prism\Concerns\Withable;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;

/**
 * @method self withEmbeddings(array $embeddings)
 * @method self withUsage(EmbeddingsUsage $usage)
 * @method self withMeta(Meta $meta)
 */
readonly class EmbeddingsResponseFake extends EmbeddingResponse
{
    use Withable;

    public static function make(): self
    {
        return new self(
            embeddings: [],
            usage: new EmbeddingsUsage(10),
            meta: new Meta('fake-id', 'fake-model'),
        );
    }
}
