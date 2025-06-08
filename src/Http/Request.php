<?php

declare(strict_types=1);

namespace Prism\Prism\Http;

class Request
{
    /**
     * @param  array<string, mixed>  $options
     */
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

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * @return array<string>|null
     */
    public function header(string $name): ?array
    {
        $header = $this->options['headers'][$name] ?? null;

        if (is_null($header)) {
            return null;
        }

        return is_array($header) ? $header : [$header];
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->options['headers'] ?? [];
    }

    public function data(): mixed
    {
        return $this->options['json'] ?? $this->options['form_params'] ?? $this->options['body'] ?? null;
    }

    public function body(): string
    {
        // If JSON data exists, encode it as a string
        if (isset($this->options['json'])) {
            return json_encode($this->options['json']);
        }

        // If form params exist, encode them as a query string
        if (isset($this->options['form_params'])) {
            return http_build_query($this->options['form_params']);
        }

        // If body exists, return it as a string
        if (isset($this->options['body'])) {
            return (string) $this->options['body'];
        }

        return '';
    }
}
