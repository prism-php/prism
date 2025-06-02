<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

trait InitializesClient
{
    /**
     * @return array<string, string>
     */
    protected function getHeaders(): array
    {
        return [];
    }

    protected function getToken(): string
    {
        return '';
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array{}|array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}  $retry
     */
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        $baseUrl ??= $this->url;
        $headers = $this->getHeaders();
        $token = $this->getToken();

        return Http::when($token !== '', fn ($client) => $client->withToken($token))
            ->when($headers !== [], fn ($client) => $client->withHeaders($headers))
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl);
    }
}
