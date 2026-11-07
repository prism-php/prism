<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\ValueObjects;

/**
 * Validated billing facts, not credentials or raw response content.
 *
 * @phpstan-type Facts array{is_byok: bool|null, router_cost: float|null, upstream_cost: float|null, complete: bool}
 */
class Billing
{
    /** @param array<string, mixed> $usage
     * @return Facts
     */
    public static function fromUsage(array $usage): array
    {
        $byok = is_bool($usage['is_byok'] ?? null) ? $usage['is_byok'] : null;
        $router = self::number($usage['cost'] ?? null);
        $upstream = self::number(data_get($usage, 'cost_details.upstream_inference_cost'));
        $complete = $router !== null && ($byok === false || ($byok === true && $upstream !== null && is_finite($router + $upstream)));

        return ['is_byok' => $byok, 'router_cost' => $router, 'upstream_cost' => $upstream, 'complete' => $complete];
    }

    /** @param Facts $facts */
    public static function cost(array $facts): ?float
    {
        // Legacy responses without BYOK identity retain their scalar cost, but are not complete evidence.
        if ($facts['is_byok'] !== true) {
            return $facts['router_cost'];
        }

        return $facts['complete'] ? $facts['router_cost'] + $facts['upstream_cost'] : null;
    }

    /** @param list<Facts> $steps
     * @return Facts
     */
    public static function combine(array $steps): array
    {
        if ($steps === []) {
            return self::fromUsage([]);
        }
        $identity = $steps[0]['is_byok'] ?? null;
        $router = 0.0;
        $upstream = 0.0;
        $total = 0.0;
        $complete = true;
        foreach ($steps as $step) {
            if ($step['is_byok'] !== $identity) {
                $identity = null;
            }
            $router = $router !== null && $step['router_cost'] !== null ? self::number($router + $step['router_cost']) : null;
            $upstream = $upstream !== null && $step['upstream_cost'] !== null ? self::number($upstream + $step['upstream_cost']) : null;
            $cost = self::cost($step);
            $total = $total !== null && $cost !== null ? self::number($total + $cost) : null;
            $complete = $complete && $step['complete'] && $total !== null;
        }

        return ['is_byok' => $identity, 'router_cost' => $router, 'upstream_cost' => $upstream, 'complete' => $complete];
    }

    /** @param list<Facts> $steps */
    public static function totalCost(array $steps): ?float
    {
        if ($steps === []) {
            return null;
        }
        $total = 0.0;
        foreach ($steps as $step) {
            $cost = self::cost($step);
            if ($cost === null || ($total = self::number($total + $cost)) === null) {
                return null;
            }
        }

        return $total;
    }

    /** @return Facts */
    public static function fromFacts(mixed $facts): array
    {
        $facts = is_array($facts) ? $facts : [];
        $billing = self::fromUsage(['is_byok' => $facts['is_byok'] ?? null, 'cost' => $facts['router_cost'] ?? null,
            'cost_details' => ['upstream_inference_cost' => $facts['upstream_cost'] ?? null]]);
        $billing['complete'] = $billing['complete'] && ($facts['complete'] ?? false) === true;

        return $billing;
    }

    protected static function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? (float) $value : null;
    }
}
