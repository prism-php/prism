<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Prism\Prism\Enums\Provider;

readonly class Image
{
    public function __construct(
        public string $image,
        public ?string $mimeType = null,
    ) {}

    public static function fromPath(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("{$path} is not a file");
        }

        $content = file_get_contents($path);

        if ($content === '' || $content === '0' || $content === false) {
            throw new InvalidArgumentException("{$path} is empty");
        }

        return new self(
            base64_encode($content),
            File::mimeType($path) ?: null,
        );
    }

    public static function fromUrl(string $url, ?string $mimeType = null): self
    {
        if (! self::supportedProviders()) {
            return new self($url, $mimeType);
        }

        $content = file_get_contents($url);

        if ($content === '' || $content === '0' || $content === false) {
            throw new InvalidArgumentException("{$url} is empty or could not be accessed");
        }

        if ($mimeType === null) {
            $headers = get_headers($url, true);
            $mimeType = $headers['Content-Type'] ?? null;

            if (is_array($mimeType)) {
                $mimeType = $mimeType[count($mimeType) - 1] ?? null;
            }
        }

        return new self(
            base64_encode($content),
            $mimeType
        );
    }

    public static function fromBase64(string $image, string $mimeType): self
    {
        return new self(
            $image,
            $mimeType
        );
    }

    public function isUrl(): bool
    {
        return Str::isUrl($this->image);
    }

    /**
     * Providers that support URL images.
     */
    protected static function supportedProviders(): bool
    {
        try {
            return when(app(Provider::class), fn ($provider): bool => $provider == Provider::Gemini->value, false);
        } catch (\Throwable) {
            return false;
        }
    }
}
