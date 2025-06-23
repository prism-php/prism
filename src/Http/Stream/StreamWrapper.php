<?php

declare(strict_types=1);

namespace Prism\Prism\Http\Stream;

use Prism\Prism\Events\HttpRequestCompleted;
use Prism\Prism\Support\Trace;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class StreamWrapper implements StreamInterface
{
    protected StreamInterface $stream;

    protected string $loggedChunks = '';

    public function __construct(protected ResponseInterface $response)
    {
        $this->stream = $response->getBody();
    }

    public function __toString(): string
    {
        return $this->stream->__toString();
    }

    public function read($length): string
    {
        $chunk = $this->stream->read($length);

        if ($chunk !== '') {
            $this->loggedChunks .= $chunk;
        }

        if ($this->stream->eof()) {
            Trace::end(
                fn () => event(new HttpRequestCompleted(
                    statusCode: $this->response->getStatusCode(),
                    headers: $this->response->getHeaders(),
                    attributes: ['chunks' => $this->loggedChunks],
                ))
            );
        }

        return $chunk;
    }

    // Delegate all other methods to the original stream
    public function getContents(): string
    {
        return $this->stream->getContents();
    }

    public function close(): void
    {
        $this->stream->close();
    }

    public function detach()
    {
        return $this->stream->detach();
    }

    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    public function isWritable(): bool
    {
        return $this->stream->isWritable();
    }

    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    public function eof(): bool
    {
        return $this->stream->eof();
    }

    public function tell(): int
    {
        return $this->stream->tell();
    }

    public function rewind(): void
    {
        $this->stream->rewind();
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
    }

    public function write($string): int
    {
        return $this->stream->write($string);
    }

    public function getMetadata($key = null): mixed
    {
        return $this->stream->getMetadata();
    }
}
