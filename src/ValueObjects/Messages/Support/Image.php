<?php

declare(strict_types=1);

namespace EchoLabs\Prism\ValueObjects\Messages\Support;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class Image
{
    public function __construct(
        public readonly string $image,
        public readonly ?string $mimeType = null,
    ) {}

    public static function fromPath(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("{$path} is not a file");
        }

        return new self(
            base64_encode(file_get_contents($path)),
            File::mimeType($path),
        );
    }

    public static function fromUrl(string $url): self
    {
        return new self($url);
    }

    public static function fromBase64(string $image, string $mimeType = 'image/jpeg'): self
    {
        return new self(
            $image,
            $mimeType
        );
    }
}
