<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

use Closure;
use Prism\Prism\ValueObjects\ToolCall;
use Throwable;

/** @internal Host process state; never carried in request data or child jobs. */
class ToolResultClassifier
{
    /** @var (Closure(ToolCall, mixed): mixed)|null */
    private static ?Closure $classifier = null;

    /** @param (Closure(ToolCall, mixed): mixed)|null $classifier */
    public static function register(?Closure $classifier): void
    {
        self::$classifier = $classifier;
    }

    public static function failure(ToolCall $call, mixed $result): ?string
    {
        $classifier = self::$classifier;
        if (! $classifier instanceof Closure) {
            return null;
        }
        try {
            $kind = $classifier($call, $result);
        } catch (Throwable) {
            return 'classifier_error';
        }

        if ($kind === null) {
            return null;
        }

        return is_string($kind) && preg_match('/\A[A-Za-z0-9_.:\-]{1,64}\z/D', $kind) === 1
            ? $kind : 'classified_failure';
    }
}
