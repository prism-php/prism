<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

use Closure;
use Prism\Prism\Tool;
use Throwable;

/** @internal Process-local application boot policy; never serialized with tools. */
class ToolErrorHandler
{
    /** @var (Closure(Throwable, Tool, array<int|string, mixed>): mixed)|null */
    private static ?Closure $handler = null;

    public static function register(?Closure $handler): void
    {
        self::$handler = $handler;
    }

    /** @param array<int|string, mixed> $params */
    public static function message(Throwable $error, Tool $tool, array $params): ?string
    {
        $handler = self::$handler;
        if (! $handler instanceof Closure) {
            return null;
        }
        try {
            $message = $handler($error, $tool, $params);
        } catch (Throwable) {
            return 'The tool failed.';
        }

        return is_string($message) ? $message : 'The tool failed.';
    }
}
