<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

use Prism\Prism\ValueObjects\Meta;

readonly class MetaChunk
{
    /**
     * @param  array<string,mixed>  $additionalContent
     */
    public function __construct(
        public Meta $meta,
        public array $additionalContent = []
    ) {}
}
