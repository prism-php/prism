<?php

declare(strict_types=1);

namespace Prism\Prism\Moderation;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ModerationResult;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class Response implements Arrayable
{
    /**
     * @param  ModerationResult[]  $results
     * @param  array<string,mixed>|null  $raw
     */
    public function __construct(
        public array $results,
        public Meta $meta,
        public ?array $raw = null
    ) {}

    /**
     * Check if any of the results are flagged
     */
    public function isFlagged(): bool
    {
        foreach ($this->results as $result) {
            if ($result->flagged) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the first flagged result, if any
     */
    public function firstFlagged(): ?ModerationResult
    {
        foreach ($this->results as $result) {
            if ($result->flagged) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Get all flagged results
     *
     * @return ModerationResult[]
     */
    public function flagged(): array
    {
        $flagged = [];

        foreach ($this->results as $result) {
            if ($result->flagged) {
                $flagged[] = $result;
            }
        }

        return $flagged;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'results' => array_map(fn (ModerationResult $result): array => $result->toArray(), $this->results),
            'meta' => $this->meta->toArray(),
            'raw' => $this->raw,
        ];
    }
}
