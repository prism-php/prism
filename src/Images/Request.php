<?php

declare(strict_types=1);

namespace Prism\Prism\Images;

use Closure;
use Prism\Prism\Concerns\ChecksSelf;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

class Request implements PrismRequest
{
    use ChecksSelf, HasProviderOptions;

    /**
     * @param  SystemMessage[]  $systemPrompts
     * @param  array<string, mixed>  $clientOptions
     * @param  array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}  $clientRetry
     * @param  array<string, mixed>  $providerOptions
     * @param  array<int, Image>  $additionalContent
     */
    public function __construct(
        protected string $model,
        protected string $providerKey,
        protected array $systemPrompts,
        protected ?string $prompt,
        protected array $clientOptions,
        protected array $clientRetry,
        protected array $additionalContent,
        array $providerOptions = [],
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

    public function prompt(): ?string
    {
        return $this->prompt;
    }

    /**
     * @return SystemMessage[]
     */
    public function systemPrompts(): array
    {
        return $this->systemPrompts;
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

    /**
     * @return array<int, Image>
     */
    public function additionalContent(): array
    {
        return $this->additionalContent;
    }
}
