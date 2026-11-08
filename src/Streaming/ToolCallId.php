<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming;

use Closure;
use Prism\Prism\Exceptions\PrismException;

/** Preserve provider identities across deltas and resolve missing IDs once. */
class ToolCallId
{
    public static function remember(?string $current, ?string $incoming): ?string
    {
        return ($current === '' ? null : $current) ?? ($incoming === '' ? null : $incoming);
    }

    /** @param (Closure(): string)|null $fallback */
    public static function resolve(?string $id, string $provider, ?Closure $fallback = null, string $format = '/\A.+\z/sD'): string
    {
        if ($id !== null && $id !== '') {
            return $id;
        }
        $generated = $fallback instanceof Closure ? $fallback() : EventID::generate($provider);
        if ($generated === '' || preg_match($format, $generated) !== 1) {
            throw new PrismException('The generated streaming tool-call ID does not match the declared format.');
        }

        return $generated;
    }
}
