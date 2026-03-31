<?php

declare(strict_types=1);

namespace Prism\Prism\Embeddings;

use InvalidArgumentException;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Text;

readonly class Content
{
    /** @var array<int, Media|Text> */
    private array $parts;

    /**
     * @param  array<int, Media|Text|string>  $parts
     */
    public function __construct(array $parts)
    {
        if ($parts === []) {
            throw new InvalidArgumentException('Embeddings content must contain at least one part.');
        }

        $this->parts = array_map(
            static fn (Media|Text|string $part): Media|Text => is_string($part) ? new Text($part) : $part,
            $parts,
        );
    }

    /**
     * @param  array<int, Media|Text|string>  $parts
     */
    public static function make(array $parts): self
    {
        return new self($parts);
    }

    /**
     * @return array<int, Media|Text>
     */
    public function parts(): array
    {
        return $this->parts;
    }
}
