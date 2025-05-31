<?php

declare(strict_types=1);

namespace Prism\Prism\Rerank;

use Override;
use Prism\Prism\Concerns\ChecksSelf;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Contracts\PrismRequest;

class Request implements PrismRequest
{
    use ChecksSelf, HasProviderOptions;

    /**
     * @param  array<string>  $documents
     * @param  array<string, mixed>  $clientOptions
     * @param  array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}  $clientRetry
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(
        public readonly string $model,
        public readonly string $query,
        public readonly array $documents,
        public readonly array $clientOptions,
        public readonly array $clientRetry,
        array $providerOptions,
    ) {
        $this->providerOptions = $providerOptions;
    }

    /**
     * @return array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool} $clientRetry
     */
    public function clientRetry(): array
    {
        return $this->clientRetry;
    }

    /**
     * @return array<string, mixed> $clientOptions
     */
    public function clientOptions(): array
    {
        return $this->clientOptions;
    }

    /**
     * @return sting $query
     */
    public function query(): string
    {
        return $this->query;
    }

    /**
     * @return array<string> $documents
     */
    public function documents(): array
    {
        return $this->documents;
    }

    #[Override]
    public function model(): string
    {
        return $this->model;
    }
}
