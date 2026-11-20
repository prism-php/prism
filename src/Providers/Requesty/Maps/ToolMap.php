<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Requesty\Maps;

use Prism\Prism\Tool;

class ToolMap
{
    /**
     * @param  array<int, Tool>  $tools
     * @return array<int, mixed>
     */
    public static function map(array $tools): array
    {
        return array_map(fn (Tool $tool): array => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                ...((bool) $tool->providerOptions('strict') ? ['strict' => true] : []),
                ...($tool->hasParameters() || (bool) $tool->providerOptions('strict')) ? [
                    'parameters' => $tool->parametersAsSchema(),
                ] : [],
            ],
        ], $tools);
    }
}
