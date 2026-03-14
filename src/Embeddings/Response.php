<?php

declare(strict_types=1);

namespace Prism\Prism\Embeddings;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class Response implements Arrayable
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

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'embeddings' => array_map(fn (Embedding $embedding): array => $embedding->toArray(), $this->embeddings),
            'usage' => $this->usage->toArray(),
            'meta' => $this->meta->toArray(),
            'raw' => $this->raw,
        ];
    }
}
