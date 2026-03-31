<?php

declare(strict_types=1);

namespace Prism\Prism\Embeddings;

use Closure;
use Prism\Prism\Concerns\ChecksSelf;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Text;

class Request implements PrismRequest
{
    use ChecksSelf, HasProviderOptions;

    /**
     * @param  array<string, mixed>  $clientOptions
     * @param  array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}  $clientRetry
     * @param  array<string, mixed>  $providerOptions
     * @param  array<Content>  $contents
     */
    public function __construct(
        protected string $model,
        protected string $providerKey,
        protected array $clientOptions,
        protected array $clientRetry,
        array $providerOptions = [],
        protected array $contents = [],
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
     * @return array<string>
     */
    public function inputs(): array
    {
        $inputs = [];

        foreach ($this->contents as $content) {
            $parts = $content->parts();

            if (count($parts) === 1 && $parts[0] instanceof Text) {
                $inputs[] = $parts[0]->text;
            }
        }

        return $inputs;
    }

    /**
     * @return array<Image>
     */
    public function images(): array
    {
        $images = [];

        foreach ($this->contents as $content) {
            $parts = $content->parts();

            if (count($parts) === 1 && $parts[0] instanceof Image) {
                $images[] = $parts[0];
            }
        }

        return $images;
    }

    /**
     * @return array<Content>
     */
    public function contents(): array
    {
        return $this->contents;
    }

    public function hasImages(): bool
    {
        return $this->images() !== [];
    }

    public function hasContents(): bool
    {
        return $this->contents !== [];
    }

    public function hasInputs(): bool
    {
        return $this->inputs() !== [];
    }

    #[\Override]
    public function model(): string
    {
        return $this->model;
    }

    public function provider(): string
    {
        return $this->providerKey;
    }
}
