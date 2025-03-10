<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Providers\Gemini\Maps\ToolChoiceMap;

it('maps string tool choice to ANY mode with allowed function', function (): void {
    expect(ToolChoiceMap::map('weather'))->toBe([
        'function_calling_config' => [
            'mode' => 'ANY',
            'allowed_function_names' => ['weather'],
        ],
    ]);
});

it('maps ToolChoice::Auto to AUTO mode', function (): void {
    expect(ToolChoiceMap::map(ToolChoice::Auto))->toBe([
        'function_calling_config' => [
            'mode' => 'AUTO',
        ],
    ]);
});

it('maps ToolChoice::None to NONE mode', function (): void {
    expect(ToolChoiceMap::map(ToolChoice::None))->toBe([
        'function_calling_config' => [
            'mode' => 'NONE',
        ],
    ]);
});

it('maps ToolChoice::Any to ANY mode', function (): void {
    expect(ToolChoiceMap::map(ToolChoice::Any))->toBe([
        'function_calling_config' => [
            'mode' => 'ANY',
        ],
    ]);
});

it('maps null to null', function (): void {
    expect(ToolChoiceMap::map(null))->toBe(null);
});
