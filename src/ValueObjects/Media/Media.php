<?php

namespace Prism\Prism\ValueObjects\Media;

use finfo;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Prism\Prism\Concerns\HasProviderOptions;

class Media
{
    use HasProviderOptions;

    protected ?string $localPath = null;

    protected ?string $storagePath = null;

    protected ?string $url = null;

    protected ?string $rawContent = null;

    protected ?string $base64 = null;

    protected ?string $mimeType = null;

    final public function __construct() {}

    /**
     * @deprecated Use `fromLocalPath()` instead.
     */
    public static function fromPath(string $path): static
    {
        return self::fromLocalPath($path);
    }

    public static function fromLocalPath(string $path): static
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("$path is not a file");
        }

        $content = file_get_contents($path) ?: '';

        if (in_array(trim($content), ['', '0'], true)) {
            throw new InvalidArgumentException("$path is empty");
        }

        $mimeType = File::mimeType($path);

        if ($mimeType === false) {
            throw new InvalidArgumentException("Could not determine mime type for {$path}");
        }

        $instance = new static;

        $instance->localPath = $path;
        $instance->rawContent = $content;
        $instance->mimeType = $mimeType;

        return $instance;
    }

    public static function fromStoragePath(string $path, ?string $diskName = null): static
    {
        /** @var FilesystemAdapter */
        $disk = Storage::disk($diskName);

        $diskName ??= 'default';

        if ($disk->exists($path) === false) {
            throw new InvalidArgumentException("$path does not exist on the '$diskName' disk");
        }

        $content = $disk->get($path);

        if (in_array(trim($content ?? ''), ['', '0'], true)) {
            throw new InvalidArgumentException("$path on the '$diskName' disk is empty.");
        }

        $mimeType = $disk->mimeType($path);

        if ($mimeType === false) {
            throw new InvalidArgumentException("Could not determine mime type for {$path} on the '$diskName' disk");
        }

        $instance = new static;

        $instance->storagePath = $path;
        $instance->rawContent = $content;
        $instance->mimeType = $mimeType;

        return $instance;
    }

    public static function fromUrl(string $url): static
    {
        $instance = new static;
        $instance->url = $url;

        return $instance;
    }

    public static function fromRawContent(string $rawContent, ?string $mimeType = null): static
    {
        $instance = new static;

        $instance->rawContent = $rawContent;
        $instance->mimeType = $mimeType;

        return $instance;
    }

    public static function fromBase64(string $base64, ?string $mimeType = null): static
    {
        $instance = new static;

        $instance->base64 = $base64;
        $instance->mimeType = $mimeType;

        return $instance;
    }

    public function isFile(): bool
    {
        return $this->localPath !== null || $this->storagePath !== null;
    }

    public function isUrl(): bool
    {
        return $this->url !== null;
    }

    public function hasBase64(): bool
    {
        return $this->hasRawContent();
    }

    public function hasRawContent(): bool
    {
        if ($this->base64 !== null) {
            return true;
        }
        if ($this->rawContent !== null) {
            return true;
        }
        if ($this->isFile()) {
            return true;
        }

        return $this->isUrl();
    }

    public function localPath(): ?string
    {
        return $this->localPath;
    }

    public function storagePath(): ?string
    {
        return $this->storagePath;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function rawContent(): ?string
    {
        if ($this->rawContent) {
            return $this->rawContent;
        }
        if ($this->localPath) {
            $this->rawContent = file_get_contents($this->localPath) ?: null;
        } elseif ($this->storagePath) {
            $this->rawContent = Storage::get($this->storagePath);
        } elseif ($this->isUrl()) {
            $this->fetchUrlContent();
        } elseif ($this->hasBase64()) {
            $this->rawContent = base64_decode((string) $this->base64);
        }

        return $this->rawContent;
    }

    public function base64(): ?string
    {
        if ($this->base64) {
            return $this->base64;
        }

        return $this->base64 = base64_encode((string) $this->rawContent());
    }

    public function mimeType(): ?string
    {
        if ($this->mimeType) {
            return $this->mimeType;
        }

        if ($content = $this->rawContent()) {
            $this->mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: null;
        }

        return $this->mimeType;
    }

    /**
     * Get a file resource suitable for HTTP multipart uploads
     *
     * @return resource
     */
    public function resource()
    {
        if ($this->localPath) {
            $resource = fopen($this->localPath, 'r');
            if ($resource === false) {
                throw new InvalidArgumentException("Cannot open file: {$this->localPath}");
            }

            return $resource;
        }

        if ($this->url) {
            $this->fetchUrlContent();

            return $this->resource();
        }

        if ($this->rawContent || $this->base64) {
            return $this->createStreamFromContent($this->rawContent());
        }

        throw new InvalidArgumentException('Cannot create resource from media');
    }

    public function fetchUrlContent(): void
    {
        if (! $this->url) {
            return;
        }

        $content = Http::get($this->url)->body();

        if (in_array(trim($content), ['', '0'], true)) {
            throw new InvalidArgumentException("{$this->url} returns no content.");
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

        if ($mimeType === false) {
            throw new InvalidArgumentException("Could not determine mime type for {$this->url}.");
        }

        $this->rawContent = $content;
    }

    /**
     * @return resource
     */
    protected function createStreamFromContent(?string $content)
    {
        if ($content === null) {
            throw new InvalidArgumentException('Cannot create stream from null content');
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new InvalidArgumentException('Cannot create temporary stream');
        }

        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
