<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Prism\Prism\Concerns\HasProviderMeta;

/**
 * Note: Prism currently only supports Documents with Anthropic.
 */
class Document
{
    use HasProviderMeta;

    public readonly string $dataFormat;

    /**
     * @param  string|array<string>  $document
     */
    public function __construct(
        public readonly string|array $document,
        public readonly ?string $mimeType,
        ?string $dataFormat = null,
        public readonly ?string $documentTitle = null,
        public readonly ?string $documentContext = null,
    ) {
        // Done this way to avoid assigning a readonly property twice.
        if ($dataFormat !== null) {
            $this->dataFormat = $dataFormat;

            return;
        }

        if (is_array($document)) {
            $this->dataFormat = 'content';

            return;
        }

        if ($this->mimeType === null) {
            throw new InvalidArgumentException('mimeType is required when document is not an array.');
        }

        $this->dataFormat = Str::startsWith($this->mimeType, 'text/') ? 'text' : 'base64';
    }

    public static function fromPath(string $path, ?string $title = null, ?string $context = null): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("{$path} is not a file");
        }

        $content = file_get_contents($path);

        if ($content === '' || $content === '0' || $content === false) {
            throw new InvalidArgumentException("{$path} is empty");
        }

        $mimeType = File::mimeType($path);

        if ($mimeType === false) {
            throw new InvalidArgumentException("Could not determine mime type for {$path}");
        }

        $isText = Str::startsWith($mimeType, 'text/');

        return new self(
            document: $isText ? $content : base64_encode($content),
            mimeType: $mimeType,
            dataFormat: $isText ? 'text' : 'base64',
            documentTitle: $title,
            documentContext: $context
        );
    }

    public static function fromBase64(string $document, string $mimeType, ?string $title = null, ?string $context = null): self
    {
        return new self(
            document: $document,
            mimeType: $mimeType,
            dataFormat: 'base64',
            documentTitle: $title,
            documentContext: $context
        );
    }

    public static function fromText(string $text, ?string $title = null, ?string $context = null): self
    {
        return new self(
            document: $text,
            mimeType: 'text/plain',
            dataFormat: 'text',
            documentTitle: $title,
            documentContext: $context
        );
    }

    /**
     * @param  array<string>  $chunks
     */
    public static function fromChunks(array $chunks, ?string $title = null, ?string $context = null): self
    {
        return new self(
            document: $chunks,
            mimeType: null,
            dataFormat: 'content',
            documentTitle: $title,
            documentContext: $context
        );
    }

    public static function fromUrl(string $url, ?string $title = null, ?string $context = null): self
    {
        $mimeType = get_headers($url, true)['Content-Type'] ?? null;

        if (is_array($mimeType)) {
            $mimeType = $mimeType[count($mimeType) - 1] ?? null;
        }

        if (is_null($mimeType)) {
            throw new InvalidArgumentException("Could not determine mime type for {$url}");
        }

        return new self(
            document: $url,
            mimeType: $mimeType,
            dataFormat: 'url',
            documentTitle: $title,
            documentContext: $context
        );
    }

    public function isUrl(): bool
    {
        return $this->dataFormat === 'url';
    }
}
