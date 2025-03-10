<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Prism\Prism\Enums\ToolChoice;

class ToolChoiceMap
{
    /**
     * @return array<string, mixed>|string|null
     */
    public static function map(string|ToolChoice|null $toolChoice): string|array|null
    {
        if (is_string($toolChoice)) {
            return [
                'function_calling_config' => [
                    'mode' => 'ANY',
                    'allowed_function_names' => [$toolChoice],
                ],
            ];
        }

        return match ($toolChoice) {
            ToolChoice::Any => ['function_calling_config' => ['mode' => 'ANY']],
            ToolChoice::Auto => ['function_calling_config' => ['mode' => 'AUTO']],
            ToolChoice::None => ['function_calling_config' => ['mode' => 'NONE']],
            null => $toolChoice,
        };
    }
}
