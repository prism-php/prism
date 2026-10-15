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

        if (self::supportsStructuredMode($baseModel)) {
            return StructuredMode::Structured;
        }

        return StructuredMode::Json;
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

    protected static function supportsStructuredMode(string $model): bool
    {
        // Prefix matching for model families where structured output is a
        // baseline feature — all versions and dated snapshots qualify.
        foreach ([
            'gpt-4o',
            'gpt-4.1',
            'gpt-4.5',
            'gpt-5',
            'chatgpt-4o',
            'o3-mini',
        ] as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }

        // Families recognised by OpenAI's naming rather than listed one by one.
        // A list goes stale in the unsafe direction: the newest models fall back
        // to JSON mode, where the schema is not enforced, and nothing says so.
        // gpt-6.x and o1/o3/o4 did exactly that until prism#83. A name only has
        // to LOOK like a family to be caught, so custom models served through
        // this provider under other names keep the JSON-mode default.
        if (preg_match('/^gpt-(\d+)(?:[.\-]|$)/', $model, $match) === 1) {
            return (int) $match[1] >= 5;
        }

        // The o-series reasoning models. o1-mini and o1-preview never supported
        // structured output; unsupported() refuses them before this is reached.
        return preg_match('/^o\d+(?:-|$)/', $model) === 1;
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
