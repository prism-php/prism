<?php

namespace Prism\Prism\ValueObjects\Media;

use finfo;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Prism\Prism\Concerns\HasProviderOptions;

/**
 * @implements Arrayable<string, mixed>
 */
class Media implements Arrayable
{
    use HasProviderOptions;

    protected ?string $fileId = null;

    protected ?string $localPath = null;

    protected ?string $storagePath = null;

    protected ?string $rawContent = null;

    protected ?string $filename = null;

    public function __construct(
        public ?string $url = null,
        public ?string $base64 = null,
        public ?string $mimeType = null) {}

    public static function fromFileId(string $fileId): static
    {
        /** @phpstan-ignore-next-line */
        $instance = new static;
        $instance->fileId = $fileId;

        return $instance;
    }

    /**
     * @deprecated Use `fromLocalPath()` instead.
     */
    public static function fromPath(string $path): static
    {
        return self::fromLocalPath($path);
    }

    public static function fromLocalPath(string $path, ?string $mimeType = null): static
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("$path is not a file");
        }

        $content = file_get_contents($path) ?: '';

        if ($content === '' || $content === '0') {
            throw new InvalidArgumentException("$path is empty");
        }

        if (! $mimeType && ! ($mimeType = File::mimeType($path))) {
            throw new InvalidArgumentException("Could not determine mime type for {$path}");
        }

        /** @phpstan-ignore-next-line */
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

        if (! $disk->exists($path)) {
            throw new InvalidArgumentException("$path does not exist on the '$diskName' disk");
        }

        $content = $disk->get($path);

        if (! $content) {
            throw new InvalidArgumentException("$path on the '$diskName' disk is empty.");
        }

        $mimeType = $disk->mimeType($path);

        if (! $mimeType) {
            throw new InvalidArgumentException("Could not determine mime type for {$path} on the '$diskName' disk");
        }

        /** @phpstan-ignore-next-line */
        $instance = new static;

        $instance->storagePath = $path;
        $instance->rawContent = $content;
        $instance->mimeType = $mimeType;

        return $instance;
    }

    public static function fromUrl(string $url, ?string $mimeType = null): static
    {
        /** @phpstan-ignore-next-line */
        $instance = new static;

        $instance->url = $url;
        $instance->mimeType = $mimeType;

        return $instance;
    }

    public static function fromRawContent(string $rawContent, ?string $mimeType = null): static
    {
        /** @phpstan-ignore-next-line */
        $instance = new static;

        $instance->rawContent = $rawContent;
        $instance->mimeType = $mimeType;

        return $instance;
    }

    public static function fromBase64(string $base64, ?string $mimeType = null): static
    {
        /** @phpstan-ignore-next-line */
        $instance = new static;

        $instance->base64 = $base64;
        $instance->mimeType = $mimeType;

        return $instance;
    }

    public function as(string $name): self
    {
        $this->filename = $name;

        return $this;
    }

    public function filename(): ?string
    {
        return $this->filename;
    }

    public function isFileId(): bool
    {
        return $this->fileId !== null;
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

    public function hasMimeType(): bool
    {
        return $this->mimeType !== null;
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

    public function hasUrl(): bool
    {
        return $this->url !== null;
    }

    public function fileId(): ?string
    {
        return $this->fileId;
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

            return $this->createStreamFromContent($this->rawContent());
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

        /** @var Response */
        $response = Http::get($this->url);
        $content = $response->body();

        if (! $content) {
            throw new InvalidArgumentException("{$this->url} returns no content.");
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

        if (! $mimeType) {
            throw new InvalidArgumentException("Could not determine mime type for {$this->url}.");
        }

        $this->rawContent = $content;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'base64' => $this->base64,
            'mime_type' => $this->mimeType,
            'file_id' => $this->fileId,
            'local_path' => $this->localPath,
            'storage_path' => $this->storagePath,
            'filename' => $this->filename,
        ];
    }

    /**
     * @return resource
     */
    protected function createStreamFromContent(?string $content)
    {
        if ($content === null) {
            throw new InvalidArgumentException('Cannot create stream from null content');
        }

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new InvalidArgumentException('Cannot create memory stream');
        }

        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
