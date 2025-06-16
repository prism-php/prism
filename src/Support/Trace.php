<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

class Trace
{
    protected static string $stackName = 'prism::traces';

    /**
     * @return array{traceId: string, parentTraceId: string|null, traceName: string, startTime: float, endTime: float|null}
     */
    public static function begin(string $operation, ?callable $callback = null): array
    {
        $data = [
            'traceId' => Str::uuid()->toString(),
            'parentTraceId' => self::getParentId(),
            'traceName' => $operation,
            'startTime' => microtime(true),
            'endTime' => null,
        ];

        Context::pushHidden(self::$stackName, $data);

        if ($callback) {
            $callback();
        }

        return $data;
    }

    public static function end(?callable $callback = null): void
    {
        $current = Context::popHidden(self::$stackName);

        $current['endTime'] = microtime(true);

        Context::pushHidden(self::$stackName, $current);

        if ($callback) {
            $callback();
        }

        Context::popHidden(self::$stackName);
    }

    /**
     * @return array{traceId: string, parentTraceId: string|null, traceName: string, startTime: float, endTime: float|null}|null
     */
    public static function get(): ?array
    {
        $traces = Context::getHidden(self::$stackName);

        if (is_array($traces) && $traces !== []) {
            return end($traces);
        }

        return null;
    }

    /**
     * @param  array{traceId: string, parentTraceId: string|null, traceName: string, startTime: float, endTime: float|null}  $trace
     */
    public static function isSameType(array $trace): bool
    {
        $current = self::get();

        return $trace['traceName'] === ($current ? $current['traceName'] : null);
    }

    protected static function getParentId(): ?string
    {
        return self::get()['traceId'] ?? null;
    }
}
