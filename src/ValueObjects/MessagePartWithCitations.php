<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

class MessagePartWithCitations
{
    public function __construct(
        public string $outputText,
        /**
         * @var array<Citation>
         */
        public array $citations = [],
        /**
         * Provider specific content.
         *
         * @var array<string,mixed>
         */
        public array $additionalContent = []
    ) {}
}
