<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Support;

use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Exceptions\PrismException;

class StructuredModeResolver
{
    public static function forModel(string $model): StructuredMode
    {
        if (self::unsupported($model)) {
            throw new PrismException(sprintf('Structured output is not supported for %s', $model));
        }

        if (self::supportsStructuredMode($model)) {
            return StructuredMode::Structured;
        }

        return StructuredMode::Json;
    }

    protected static function supportsStructuredMode(string $model): bool
    {
        return in_array($model, [
            'gpt-4o-mini',
            'gpt-4o-mini-2024-07-18',
            'gpt-4o-2024-08-06',
            'gpt-4o',
            'chatgpt-4o-latest',
            'o3-mini',
            'o3-mini-2025-01-31',
            'gpt-4.5-preview',
            'gpt-4.5-preview-2025-02-27',
        ]);
    }

    protected static function supportsJsonMode(string $model): bool
    {
        if (preg_match('/^gpt-4-.*/', $model)) {
            return true;
        }

        return $model === 'gpt-3.5-turbo';
    }

    protected static function unsupported(string $model): bool
    {
        return in_array($model, [
            'o1-mini',
            'o1-mini-2024-09-12',
            'o1-preview',
            'o1-preview-2024-09-12',
        ]);
    }
}
