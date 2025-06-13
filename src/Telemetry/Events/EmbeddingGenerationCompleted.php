<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response;

class EmbeddingGenerationCompleted
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $spanId,
        public readonly Request $request,
        public readonly Response $response,
        public readonly array $context = []
    ) {}
}
