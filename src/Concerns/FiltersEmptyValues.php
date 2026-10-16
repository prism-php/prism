<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

trait FiltersEmptyValues
{
    /**
     * Drops what a bare `array_filter()` drops, except the string "0":
     * that is real message content, such as an answer to "how many?".
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param  array<TKey, TValue>  $values
     * @return array<TKey, TValue>
     */
    protected static function withoutEmptyValues(array $values): array
    {
        return array_filter(
            $values,
            fn (mixed $value): bool => $value === '0' || (bool) $value
        );
    }
}
