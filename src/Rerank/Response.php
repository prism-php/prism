<?php

declare(strict_types=1);

namespace Prism\Prism\Rerank;

use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Rerank;
use Prism\Prism\ValueObjects\RerankUsage;

readonly class Response
{
    /**
     * @param  Rerank[]  $rerankings
     */
    public function __construct(
        public array $reranks,
        public RerankUsage $usage,
        public Meta $meta
    ) {}
}
