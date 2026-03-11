<?php

declare(strict_types=1);

namespace Prism\Prism\Audio;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\ValueObjects\GeneratedAudio;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class AudioResponse implements Arrayable
{
    public function __construct(
        public GeneratedAudio $audio,
        /** @var array<string,mixed> */
        public array $additionalContent = []
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'audio' => $this->audio->toArray(),
            'additional_content' => $this->additionalContent,
        ];
    }
}
