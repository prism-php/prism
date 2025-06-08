<?php

declare(strict_types=1);

namespace Prism\Prism\Http;

use Illuminate\Support\Collection;
use Prism\Prism\Http\Exceptions\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

class Response implements \Stringable
{
    public function __construct(protected ?ResponseInterface $response) {}

    public function __toString(): string
    {
        return $this->body();
    }

    public function body(): string
    {
        return $this->response instanceof \Psr\Http\Message\ResponseInterface ? (string) $this->response->getBody() : '';
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        if (! $this->response instanceof \Psr\Http\Message\ResponseInterface) {
            return $default;
        }

        $json = json_decode($this->body(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $default;
        }

        if (is_null($key)) {
            return $json;
        }

        return data_get($json, $key, $default);
    }

    public function object(): object
    {
        return json_decode($this->body(), false);
    }

    /**
     * @return Collection<int|string, mixed>
     */
    public function collect(?string $key = null): Collection
    {
        $data = $this->json($key) ?? [];

        return new Collection($data);
    }

    public function stream(): ?StreamInterface
    {
        return $this->response?->getBody();
    }

    public function status(): int
    {
        return $this->response?->getStatusCode() ?? 0;
    }

    public function reason(): string
    {
        return $this->response?->getReasonPhrase() ?? '';
    }

    public function successful(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    public function redirect(): bool
    {
        return $this->status() >= 300 && $this->status() < 400;
    }

    public function failed(): bool
    {
        if ($this->serverError()) {
            return true;
        }

        return $this->clientError();
    }

    public function clientError(): bool
    {
        return $this->status() >= 400 && $this->status() < 500;
    }

    public function serverError(): bool
    {
        return $this->status() >= 500;
    }

    public function ok(): bool
    {
        return $this->status() === 200;
    }

    public function created(): bool
    {
        return $this->status() === 201;
    }

    public function accepted(): bool
    {
        return $this->status() === 202;
    }

    public function noContent(): bool
    {
        return $this->status() === 204;
    }

    public function notFound(): bool
    {
        return $this->status() === 404;
    }

    public function forbidden(): bool
    {
        return $this->status() === 403;
    }

    public function unauthorized(): bool
    {
        return $this->status() === 401;
    }

    public function unprocessableEntity(): bool
    {
        return $this->status() === 422;
    }

    public function tooManyRequests(): bool
    {
        return $this->status() === 429;
    }

    public function header(string $header): string
    {
        return $this->response?->getHeaderLine($header) ?? '';
    }

    /**
     * @return array<string, array<string>>
     */
    public function headers(): array
    {
        if (! $this->response instanceof \Psr\Http\Message\ResponseInterface) {
            return [];
        }

        return $this->response->getHeaders();
    }

    public function hasHeader(string $header): bool
    {
        return $this->response?->hasHeader($header) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function cookies(): array
    {
        return [];
    }

    public function effectiveUri(): ?UriInterface
    {
        return null;
    }

    public function throw(?callable $callback = null): static
    {
        if ($this->failed()) {
            $exception = new RequestException($this);

            if ($callback) {
                $callback($this, $exception);
            }

            throw $exception;
        }

        return $this;
    }

    public function throwIf(bool|callable $condition, ?callable $throwCallback = null): static
    {
        return value($condition, $this)
            ? $this->throw($throwCallback)
            : $this;
    }

    public function throwIfStatus(callable|int $statusCode): static
    {
        if (is_callable($statusCode)) {
            return $statusCode($this->status(), $this) ? $this->throw() : $this;
        }

        return $this->status() === $statusCode ? $this->throw() : $this;
    }

    public function throwUnless(bool|callable $condition, ?callable $throwCallback = null): static
    {
        return value($condition, $this)
            ? $this
            : $this->throw($throwCallback);
    }

    public function throwIfClientError(?callable $callback = null): static
    {
        return $this->clientError() ? $this->throw($callback) : $this;
    }

    public function throwIfServerError(?callable $callback = null): static
    {
        return $this->serverError() ? $this->throw($callback) : $this;
    }

    public function getPsrResponse(): ?ResponseInterface
    {
        return $this->response;
    }
}
