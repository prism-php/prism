<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\VoyageAI\ValueObjects;

use Prism\Prism\Rerank\Request as RerankRequest;

class Rerank
{
    public function __construct(
        public float $score,
        public int $index,
        public string $document
    ) {}

    /**
     * @param  array{relevance_score: float,index: int,document?: string}  $rerank
     */
    public static function fromArray(array $rerank, RerankRequest $request): self
    {
        return new self(
            score: $rerank['relevance_score'],
            index: $rerank['index'],
            document: $rerank['document'] ?? $request->documents()[$rerank['index']],
        );
    }
}
