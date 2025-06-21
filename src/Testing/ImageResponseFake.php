<?php

declare(strict_types=1);

namespace Prism\Prism\Testing;

use Prism\Prism\Concerns\HasFluentAttributes;
use Prism\Prism\Images\Response;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

/**
 * @method self withImages(array $images)
 * @method self withUsage(Usage $usage)
 * @method self withMeta(Meta $meta)
 * @method self withAdditionalContent(array $additionalContent)
 */
readonly class ImageResponseFake extends Response
{
    use HasFluentAttributes;

    public static function make(): self
    {
        return new self(
            images: [
                new GeneratedImage(
                    url: 'https://example.com/fake-image.png',
                    revisedPrompt: null,
                ),
            ],
            usage: new Usage(0, 0),
            meta: new Meta('fake', 'fake'),
            additionalContent: [],
        );
    }
}
