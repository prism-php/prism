<?php

namespace Prism\Prism\ValueObjects;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
class ProviderRateLimit implements Arrayable
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $limit = null,
        public readonly ?int $remaining = null,
        public readonly ?Carbon $resetsAt = null
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'limit' => $this->limit,
            'remaining' => $this->remaining,
            'resets_at' => $this->resetsAt?->toIso8601String(),
        ];
    }
}
