<?php

declare(strict_types=1);

namespace Prism\Prism\Rerank;

use Prism\Prism\Providers\VoyageAI\ValueObjects\Rerank;
use Prism\Prism\Providers\VoyageAI\ValueObjects\ReranksUsage;
use Prism\Prism\ValueObjects\Meta;

readonly class Response
{
    /**
     * @param  Rerank[]  $reranks
     */
    public function __construct(
        public array $reranks,
        public ReranksUsage $usage,
        public Meta $meta
    ) {}
}
