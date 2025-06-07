<?php

declare(strict_types=1);

namespace Prism\Prism\Http;

class Request
{
    public function __construct(
        protected string $method,
        protected string $url,
        protected array $options = []
    ) {}

    public function method(): string
    {
        return $this->method;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function options(): array
    {
        return $this->options;
    }

    public function header(string $name): ?string
    {
        return $this->options['headers'][$name] ?? null;
    }

    public function headers(): array
    {
        return $this->options['headers'] ?? [];
    }

    public function data(): mixed
    {
        return $this->options['json'] ?? $this->options['form_params'] ?? $this->options['body'] ?? null;
    }
}
