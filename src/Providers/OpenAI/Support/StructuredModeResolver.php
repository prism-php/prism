<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Support;

use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Exceptions\PrismException;

class StructuredModeResolver
{
    public static function forModel(string $model): StructuredMode
    {
        $baseModel = self::resolveBaseModel($model);

        if (self::unsupported($baseModel)) {
            throw new PrismException(sprintf('Structured output is not supported for %s', $model));
        }

        if (self::requiresJsonMode($baseModel)) {
            return StructuredMode::Json;
        }

        return StructuredMode::Structured;
    }

    /**
     * Resolve the base model name, stripping the ft: prefix for fine-tuned models.
     *
     * Fine-tuned models use the format: ft:<base-model>:<org>:<name>:<hash>
     */
    protected static function resolveBaseModel(string $model): string
    {
        if (str_starts_with($model, 'ft:')) {
            $parts = explode(':', $model, 3);

            return $parts[1] ?? $model;
        }

        return $model;
    }

    /**
     * Legacy models that predate json_schema structured outputs. Every other
     * model (including unknown and custom names) gets json_schema.
     */
    protected static function requiresJsonMode(string $model): bool
    {
        if (str_starts_with($model, 'gpt-3.5-turbo')) {
            return true;
        }

        if ($model === 'gpt-4' || str_starts_with($model, 'gpt-4-')) {
            return true;
        }

        return $model === 'gpt-4o-2024-05-13';
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
