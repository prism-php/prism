<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Maps;

use InvalidArgumentException;
use Prism\Prism\Enums\ToolChoice;

class ToolChoiceMap
{
    /**
     * @return array<string, mixed>|string|null
     */
    public static function map(string|ToolChoice|null $toolChoice, int $currentStep = 0, ?int $autoAfterSteps = null): string|array|null
    {
        if (is_null($toolChoice)) {
            return null;
        }

        if (is_string($toolChoice)) {
            if (! is_null($autoAfterSteps) && $currentStep >= $autoAfterSteps) {
                return [
                    'type' => 'auto',
                ];
            }

            return [
                'type' => 'tool',
                'name' => $toolChoice,
            ];
        }

        if (! in_array($toolChoice, [ToolChoice::Auto, ToolChoice::Any, ToolChoice::None])) {
            throw new InvalidArgumentException('Invalid tool choice');
        }

        return [
            'type' => match ($toolChoice) {
                ToolChoice::Auto => 'auto',
                ToolChoice::Any => ! is_null($autoAfterSteps) && $currentStep >= $autoAfterSteps ? 'auto' : 'any',
                ToolChoice::None => 'none',
            },
        ];
    }
}
