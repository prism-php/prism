<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class Meta implements Arrayable
{
    /**
     * @param  ProviderRateLimit[]  $rateLimits
     */
    public function __construct(
        public string $id,
        public string $model,
        public array $rateLimits = [],
        public ?string $serviceTier = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'model' => $this->model,
            'rate_limits' => array_map(fn (ProviderRateLimit $rateLimit): array => $rateLimit->toArray(), $this->rateLimits),
            'service_tier' => $this->serviceTier,
        ];
    }
}
