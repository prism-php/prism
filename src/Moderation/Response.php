<?php

declare(strict_types=1);

namespace Prism\Prism\Moderation;

use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ModerationResult;

readonly class Response
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
}
