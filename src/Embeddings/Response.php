<?php

declare(strict_types=1);

namespace Prism\Prism\Embeddings;

use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;

readonly class Response
{
    /**
     * @param  Embedding[]  $embeddings
     * @param  array<string,mixed>|null  $raw
     */
    public function __construct(
        public array $embeddings,
        public EmbeddingsUsage $usage,
        public Meta $meta,
        public ?array $raw = null
    ) {}
}
