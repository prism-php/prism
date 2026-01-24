<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Media;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class Text implements Arrayable
{
    public function __construct(public string $text) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'text' => $this->text,
        ];
    }
}
