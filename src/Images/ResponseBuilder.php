<?php

declare(strict_types=1);

namespace Prism\Prism\Images;

use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

readonly class ResponseBuilder
{
    public function __construct(
        public Usage $usage,
        public Meta $meta,
        /** @var GeneratedImage[] */
        public array $images = [],
        /** @var array<string,mixed> */
        public array $additionalContent = [],
        /** @var array<string,mixed>|null */
        public ?array $raw = null
    ) {}

    public function toResponse(): Response
    {
        return new Response(
            images: $this->images,
            usage: $this->usage,
            meta: $this->meta,
            additionalContent: $this->additionalContent,
            raw: $this->raw,
        );
    }
}
