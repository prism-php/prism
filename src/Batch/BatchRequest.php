<?php

declare(strict_types=1);

namespace Prism\Prism\Batch;

readonly class BatchRequest
{
    /**
     * @param  BatchRequestItem[]  $items
     */
    public function __construct(
        public array $items,
    ) {}
}
