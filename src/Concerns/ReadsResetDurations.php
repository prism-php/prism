<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Support\Carbon;

trait ReadsResetDurations
{
    /**
     * When a rate limit resets, from a Go duration such as "20ms", "1s", "6m0s" or "1h2m3.5s", as OpenAI and Groq send it.
     */
    protected function resetsAtFromDuration(?string $duration): ?Carbon
    {
        if ($duration === null || preg_match('/^(?:\d+(?:\.\d+)?(?:ms|h|m|s))+$/', $duration) !== 1) {
            return null;
        }

        preg_match_all('/(\d+(?:\.\d+)?)(ms|h|m|s)/', $duration, $parts, PREG_SET_ORDER);

        $milliseconds = 0.0;

        foreach ($parts as [, $value, $unit]) {
            $milliseconds += (float) $value * match ($unit) {
                'ms' => 1,
                's' => 1_000,
                'm' => 60_000,
                'h' => 3_600_000,
            };
        }

        return Carbon::now()->addMilliseconds((int) round($milliseconds));
    }
}
