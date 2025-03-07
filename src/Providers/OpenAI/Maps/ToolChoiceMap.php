<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Prism\Prism\Enums\ToolChoice;

class ToolChoiceMap
{
    /**
     * @return array<string, mixed>|string|null
     */
    public static function map(string|ToolChoice|null $toolChoice, int $currentStep = 0, ?int $autoAfterSteps = null): string|array|null
    {
        if (is_string($toolChoice)) {
            if (! is_null($autoAfterSteps) && $currentStep >= $autoAfterSteps) {
                return 'auto';
            }

            return [
                'type' => 'function',
                'name' => $toolChoice,
            ];
        }

        return match ($toolChoice) {
            ToolChoice::Auto => 'auto',
            ToolChoice::Any => ! is_null($autoAfterSteps) && $currentStep >= $autoAfterSteps ? 'auto' : 'required',
            ToolChoice::None => 'none',
            null => $toolChoice,
        };
    }
}
