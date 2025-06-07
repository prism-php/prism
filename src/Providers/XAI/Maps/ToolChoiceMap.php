<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\XAI\Maps;

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
                return 'auto';
            }

            return [
                'type' => 'function',
                'function' => [
                    'name' => $toolChoice,
                ],
            ];
        }

        if (! in_array($toolChoice, [ToolChoice::Auto, ToolChoice::Any])) {
            throw new InvalidArgumentException('Invalid tool choice');
        }

        return match ($toolChoice) {
            ToolChoice::Auto => 'auto',
            ToolChoice::Any => ! is_null($autoAfterSteps) && $currentStep >= $autoAfterSteps ? 'auto' : 'required',
        };
    }
}
