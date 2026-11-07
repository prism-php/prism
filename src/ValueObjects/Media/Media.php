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
use Prism\Prism\Exceptions\PrismUrlRefused;
use Prism\Prism\Support\HostResolver;
use Prism\Prism\Support\PublicUrl;

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

        // Not `?: ''` — a file holding just "0" is falsy but not empty.
        $content = file_get_contents($path);

        if ($content === false || $content === '') {
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

    /**
     * Are the bytes ALREADY IN HAND?
     *
     * This used to delegate to `hasRawContent()`, which answers a different
     * question — "can bytes be obtained" — and therefore returned true for a
     * URL that had never been fetched:
     *
     *     Audio::fromUrl('http://169.254.169.254/…')->hasBase64();  // was true
     *
     * The name states a fact about this object's contents, so it is the natural
     * predicate for a consumer deciding whether sending this media will cause
     * an outbound request. Every such consumer got the opposite of what they
     * asked, silently: the guard passed, the request was built, the fetch
     * happened. A downstream SSRF guard written this way does nothing at all.
     *
     * `fromLocalPath()` and `fromStoragePath()` still answer TRUE, and that is
     * correct rather than an exception — both read the file at construction, so
     * by the time anyone asks, the bytes really are held here.
     *
     * Ask `hasRawContent()` when you meant "can this be resolved".
     */
    public function hasBase64(): bool
    {
        return $this->base64 !== null || $this->rawContent !== null;
    }

    public function hasMimeType(): bool
    {
        return $this->mimeType !== null;
    }

    /**
     * Can this payload produce its bytes WITHOUT a network request?
     *
     * A URL used to answer true here, because reading its bytes fetched it.
     * That fetch is gone (see {@see self::rawContent()}), so a URL on its own
     * no longer has content this object can produce — call
     * {@see self::fetchUrlContent()} first if you mean to resolve it.
     *
     * A local or storage path still answers true: both read the file at
     * construction, so the bytes are already held.
     */
    public function hasRawContent(): bool
    {
        if ($this->base64 !== null) {
            return true;
        }
        if ($this->rawContent !== null) {
            return true;
        }

        return $this->isFile();
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

    /**
     * The payload's bytes, or null when they are not already obtainable.
     *
     * READING THIS NEVER MAKES A NETWORK REQUEST. It used to: a URL payload
     * fetched itself here, through a bare `Http::get()` with no host allow-list,
     * no private-address check and redirects followed. And Prism reads media
     * bytes on ordinary provider calls, so an application that built
     * `fromUrl()` from request input and sent it to a provider that inlines
     * media had this process fetch whatever URL it named — a cloud metadata
     * endpoint included. Verified, not supposed.
     *
     * Reading a property must never perform an outbound request.
     *
     * To resolve a URL, call {@see self::fetchUrlContent()} — explicitly, with a
     * URL you have decided to trust. That call is unguarded in all three
     * languages, deliberately visible rather than implicit.
     *
     * Removing the URL branch also fixed an ordering defect: a payload carrying
     * BOTH a url and base64 used to fetch the url and ignore the bytes it
     * already held.
     */
    public function rawContent(): ?string
    {
        if ($this->rawContent !== null && $this->rawContent !== '') {
            return $this->rawContent;
        }
        if ($this->localPath) {
            // Not `?: null` — "0" is falsy but is real file content.
            $content = file_get_contents($this->localPath);

            $this->rawContent = $content === false ? null : $content;
        } elseif ($this->storagePath) {
            $this->rawContent = Storage::get($this->storagePath);
        } elseif ($this->base64 !== null) {
            $this->rawContent = base64_decode($this->base64);
        }

        return $this->rawContent;
    }

    /**
     * The payload as base64, or null when there are no bytes to encode.
     *
     * Null for a URL-only payload rather than `''`. It used to fetch the URL and
     * encode the result; with no fetch, encoding `(string) null` would cache and
     * return an empty string, which a provider mapper sends as an empty image
     * rather than failing. Null is the value a caller can actually branch on.
     */
    public function base64(): ?string
    {
        if ($this->base64 !== null && $this->base64 !== '') {
            return $this->base64;
        }

        $content = $this->rawContent();

        if ($content === null) {
            return null;
        }

        return $this->base64 = base64_encode($content);
    }

    public function mimeType(): ?string
    {
        if ($this->mimeType === null && $content = $this->rawContent()) {
            $this->mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: null;
        }

        $this->mimeType = match ($this->mimeType) {
            'audio/x-wav', 'audio/wave', 'audio/x-pn-wav', 'audio/vnd.wave' => 'audio/wav',
            default => $this->mimeType,
        };

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

        if ($this->rawContent || $this->base64) {
            return $this->createStreamFromContent($this->rawContent());
        }

        // A URL is NOT fetched to make a stream. This path used to, and every
        // audio handler and OpenAI image edits reach it on an ordinary call —
        // so a request-derived `Audio::fromUrl()` had this process fetch the
        // URL. Refused with the explicit alternative named, because a caller
        // who meant to resolve a trusted URL needs to know how.
        if ($this->url !== null) {
            throw new InvalidArgumentException(
                'Media built from a URL has no bytes to stream, and Prism no longer fetches a URL implicitly. '
                .'Call fetchUrlContent() first, with a URL you have decided to trust — reading media bytes used '
                .'to fetch them automatically, which made any request-derived URL a server-side fetch.'
            );
        }

        throw new InvalidArgumentException('Cannot create resource from media');
    }

    /**
     * Resolve a URL payload's bytes. EXPLICIT, and the only thing that does.
     *
     * Nothing in Prism calls this for you any more. That is the point: reading
     * media bytes used to call it implicitly, which turned a request-derived URL
     * into a server-side fetch on ordinary provider calls.
     *
     * **This method is unguarded** — no host allow-list, no private-address
     * check. For a URL you do not trust, use {@see self::fetchPublicUrlContent()}.
     * Calling it with a URL taken from user input is still a server-side
     * request forgery; the difference is that it is now a line you wrote rather
     * than a side effect of reading a property. Validate the URL first.
     *
     * Returns the payload so the call reads as a step: `$image->fetchUrlContent()`.
     */
    public function fetchUrlContent(): static
    {
        if (! $this->url) {
            return $this;
        }

        /** @var Response */
        $response = Http::get($this->url);
        $content = $response->body();

        if (! $content) {
            throw new InvalidArgumentException($this->safeUrl().' returns no content.');
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

        if (! $mimeType) {
            throw new InvalidArgumentException('Could not determine mime type for '.$this->safeUrl().'.');
        }

        $this->rawContent = $content;

        return $this;
    }

    /**
     * Fetch the URL, but only if it reaches somewhere the public internet can.
     *
     * FOR A URL YOU DO NOT TRUST -- one from a request, or from model output.
     * `fetchUrlContent()` is unguarded by design and stays that way; this is
     * the variant to reach for when the URL's origin is not yours, and the
     * migration that prompted it is the dangerous one: code that broke when
     * implicit fetching was removed gets an explicit fetch added wherever it
     * failed, often on exactly such a URL.
     *
     * Refused: a non-http scheme, a private, loopback or reserved address, a
     * NAME that resolves to one (the bypass an allow-list of URL strings
     * misses, since an attacker owns their own DNS), and -- the one worth
     * stating -- a REDIRECT into any of those. Redirects are followed by
     * default, so a public host answering `302 http://169.254.169.254/` would
     * defeat a guard that only checked the URL it was handed. Each hop is
     * checked before it is requested.
     *
     * NOT A SANDBOX. A name can resolve differently between the check and the
     * request, which only connection-level pinning closes. See
     * {@see PublicUrl}.
     *
     * BOUNDED. The URL is one the caller does not trust, so the size of its
     * answer is not theirs to choose either: a public host can answer with
     * gigabytes and take the worker's memory with it. The body is read
     * as a stream and refused with `media_too_large` past `$maxBytes`; a
     * declared Content-Length over it is refused before any body is read. The
     * default, 32 MiB, is the largest inline document the providers accept.
     *
     * @throws PrismUrlRefused when the URL, or a redirect from it, is not public, or the answer is over $maxBytes
     */
    public function fetchPublicUrlContent(int $maxRedirects = 5, int $maxBytes = 32 * 1024 * 1024): static
    {
        if (! $this->url) {
            return $this;
        }

        $resolver = resolve(HostResolver::class);
        $url = $this->url;
        $response = null;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            try {
                PublicUrl::assert($url, $resolver);
            } catch (PrismUrlRefused $refused) {
                // A refusal on a LATER hop is a different finding from one on
                // the URL the caller passed: their input was fine and the
                // SERVER aimed them somewhere internal. A consumer seeing
                // `redirect_refused` has learned something about the host they
                // were given, not about their own input, so the two do not
                // share a code.
                throw $hop === 0 ? $refused : new PrismUrlRefused(
                    'redirect_refused',
                    $this->safeUrl().' redirected to somewhere a guarded fetch will not follow: '.$refused->getMessage(),
                );
            }

            /** @var Response $response */
            $response = Http::withoutRedirecting()->withOptions(['stream' => true])->get($url);

            if (! $response->redirect()) {
                break;
            }

            $location = $response->header('Location');

            if ($location === '') {
                break;
            }

            // A relative Location keeps the host it came from, which was just
            // checked; an absolute one is checked on the next pass.
            $url = str_contains($location, '://')
                ? $location
                : rtrim((string) preg_replace('#(?<!/)/[^/]*$#', '', $url), '/').'/'.ltrim($location, '/');

            if ($hop === $maxRedirects) {
                throw new PrismUrlRefused('too_many_redirects', $this->safeUrl().' redirected more than '.$maxRedirects.' times.');
            }
        }

        $content = $response instanceof Response ? $this->boundedBody($response, $maxBytes) : '';

        if ($content === '' || $content === '0') {
            throw new InvalidArgumentException($this->safeUrl().' returns no content.');
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

        if (! $mimeType) {
            throw new InvalidArgumentException('Could not determine mime type for '.$this->safeUrl().'.');
        }

        $this->rawContent = $content;
        $this->mimeType = $mimeType;

        return $this;
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
     * The body, read in chunks and refused the moment it passes `$maxBytes`,
     * so an oversized answer is never held whole. A declared Content-Length
     * over the cap is refused before reading anything.
     *
     * @throws PrismUrlRefused
     */
    protected function boundedBody(Response $response, int $maxBytes): string
    {
        $declared = $response->header('Content-Length');

        if (preg_match('/^\d+$/', $declared) === 1 && (int) $declared > $maxBytes) {
            throw new PrismUrlRefused('media_too_large', $this->safeUrl().' declares '.$declared.' bytes, over the '.$maxBytes.'-byte cap.');
        }

        $stream = $response->toPsrResponse()->getBody();
        $content = '';

        while (! $stream->eof()) {
            $content .= $stream->read(65536);

            if (strlen($content) > $maxBytes) {
                $stream->close();

                throw new PrismUrlRefused('media_too_large', $this->safeUrl().' sent more than the '.$maxBytes.'-byte cap.');
            }
        }

        return $content;
    }

    /**
     * The URL with the secret-bearing parts taken out, for a message that gets logged.
     *
     * An exception message is logged and shipped to an error tracker as a
     * matter of course, and a URL carries credentials often enough that it
     * belongs in neither: userinfo holds them outright
     * (`https://user:pass@host/…`), and a presigned object-storage URL puts a
     * bearer-equivalent token in the query string. Scheme, host and path say
     * WHICH fetch failed, which is the whole job of the message.
     *
     * A query string that was present is marked rather than dropped silently —
     * "there was one, and it is not shown" is a different fact from "there was
     * none", and the difference matters when reading the failure.
     */
    protected function safeUrl(): string
    {
        $parts = parse_url((string) $this->url);

        if ($parts === false || ! isset($parts['host'])) {
            return '[url without a host]';
        }

        return sprintf(
            '%s%s%s%s%s',
            isset($parts['scheme']) ? $parts['scheme'].'://' : '',
            $parts['host'],
            isset($parts['port']) ? ':'.$parts['port'] : '',
            $parts['path'] ?? '',
            isset($parts['query']) ? '?[redacted]' : '',
        );
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
