<?php

declare(strict_types=1);

namespace Prism\Prism\Http;

use Illuminate\Support\Collection;
use Prism\Prism\Http\Exceptions\RequestException;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

class Response implements \Stringable
{
    public function __construct(protected ResponseInterface $response, protected ?string $prismProvider = null, protected array $prismOptions = [])
    {
    }
    public function __toString(): string
    {
        return $this->body();
    }

    public function body(): string
    {
        return (string) $this->response->getBody();
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        if (! $this->response) {
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

    public function collect(?string $key = null): Collection
    {
        return collect($this->json($key));
    }

    public function stream(): StreamInterface
    {
        return $this->response->getBody();
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function reason(): string
    {
        return $this->response->getReasonPhrase();
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
        return $this->response->getHeaderLine($header);
    }

    public function headers(): array
    {
        return collect($this->response->getHeaders())
            ->mapWithKeys(fn($v, $k) => [$k => $v[0]])->all();
    }

    public function hasHeader(string $header): bool
    {
        return $this->response->hasHeader($header);
    }

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

    public function getProvider(): ?string
    {
        return $this->prismProvider;
    }

    public function getRateLimit(): ?ProviderRateLimit
    {
        $headers = $this->headers();

        if ($this->prismProvider === 'openai') {
            return $this->parseOpenAIRateLimit($headers);
        }

        if ($this->prismProvider === 'anthropic') {
            return $this->parseAnthropicRateLimit($headers);
        }

        return null;
    }

    public function getUsage(): ?Usage
    {
        $data = $this->json();

        if (! $data || ! isset($data['usage'])) {
            return null;
        }

        $usage = $data['usage'];

        return new Usage(
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
        );
    }

    public function getPrismMeta(): ?Meta
    {
        $finishReason = null;
        $data = $this->json();

        if ($data && isset($data['choices'][0]['finish_reason'])) {
            $finishReason = $data['choices'][0]['finish_reason'];
        }

        return new Meta(
            $finishReason,
            $this->getUsage(),
            $this->getRateLimit()
        );
    }

    public function getPsrResponse(): ?ResponseInterface
    {
        return $this->response;
    }
    protected function parseOpenAIRateLimit(array $headers): ?ProviderRateLimit
    {
        $limit = $headers['x-ratelimit-limit-requests'] ?? null;
        $remaining = $headers['x-ratelimit-remaining-requests'] ?? null;
        $reset = $headers['x-ratelimit-reset-requests'] ?? null;

        if (! $limit || ! $remaining || ! $reset) {
            return null;
        }

        return new ProviderRateLimit(
            (int) $limit,
            (int) $remaining,
            \DateTimeImmutable::createFromFormat('U', $reset)
        );
    }
    protected function parseAnthropicRateLimit(array $headers): ?ProviderRateLimit
    {
        $limit = $headers['anthropic-ratelimit-requests-limit'] ?? null;
        $remaining = $headers['anthropic-ratelimit-requests-remaining'] ?? null;
        $reset = $headers['anthropic-ratelimit-requests-reset'] ?? null;

        if (! $limit || ! $remaining || ! $reset) {
            return null;
        }

        return new ProviderRateLimit(
            (int) $limit,
            (int) $remaining,
            \DateTimeImmutable::createFromFormat(\DateTimeInterface::ISO8601, $reset)
        );
    }
}
