<?php

declare(strict_types=1);

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenRouter\Maps\ToolCallMap;

it('maps valid tool call arguments', function (): void {
    $toolCalls = ToolCallMap::map([[
        'id' => '1',
        'function' => [
            'name' => 'demo',
            'arguments' => '{"city":"Detroit"}',
        ],
    ]]);

    expect($toolCalls[0]->arguments())->toBe(['city' => 'Detroit']);
});

it('throws a provider response error for malformed tool call arguments', function (): void {
    ToolCallMap::map([[
        'id' => '1',
        'function' => [
            'name' => 'demo',
            'arguments' => '{',
        ],
    ]]);
})->throws(PrismException::class, 'OpenRouter Error: Invalid tool call arguments JSON');
