<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Maps;

use Prism\Prism\Tool;

class ToolMap
{
    /**
     * @param  Tool[]  $tools
     * @return array<mixed>
     */
    public static function map(array $tools): array
    {
        return array_map(function (Tool $tool): array {
            $properties = $tool->parametersAsArray();

            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => [
                        'type' => 'object',
                        'properties' => $properties === [] ? new \stdClass : $properties,
                        'required' => $tool->requiredParameters(),
                    ],
                ],
            ];
        }, $tools);
    }
}
