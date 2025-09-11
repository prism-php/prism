<?php

declare(strict_types=1);

namespace Prism\Prism\File;

use Closure;
use Override;
use Prism\Prism\Concerns\ChecksSelf;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Contracts\PrismRequest;

class Request implements PrismRequest
{
    use ChecksSelf, HasProviderOptions;

    /**
     * @param  array<string, mixed>  $clientOptions
     * @param  array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}  $clientRetry
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(
        protected string $model,
        protected array $clientOptions,
        protected array $clientRetry,
        protected ?string $purpose,
        protected ?string $fileName,
        protected ?string $disk,
        protected ?string $path,
        protected ?string $fileOutputId,
        array $providerOptions = []
    ) {
        $this->providerOptions = $providerOptions;
    }

    public function fileOutputId(): ?string
    {
        return $this->fileOutputId;
    }

    public function disk(): ?string
    {
        return $this->disk;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function purpose(): ?string
    {
        return $this->purpose;
    }

    public function fileName(): ?string
    {
        return $this->fileName;
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

    #[Override]
    public function model(): string
    {
        return $this->model;
    }
}
