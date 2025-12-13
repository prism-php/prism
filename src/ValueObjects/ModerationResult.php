<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

readonly class ModerationResult
{
    /**
     * @param  array<string, bool>  $categories
     * @param  array<string, float>  $categoryScores
     */
    public function __construct(
        public bool $flagged,
        public array $categories,
        public array $categoryScores,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            flagged: (bool) data_get($data, 'flagged', false),
            categories: data_get($data, 'categories', []) ?: [],
            categoryScores: data_get($data, 'category_scores', []) ?: [],
        );
    }
}
