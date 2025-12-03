<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Maps;

use Prism\Prism\Providers\Anthropic\Concerns\NormalizesCacheControl;
use Prism\Prism\Tool as PrismTool;

class ToolMap
{
    use NormalizesCacheControl;

    /**
     * @param  PrismTool[]  $tools
     * @return array<string, mixed>
     */
    public static function map(array $tools): array
    {
        return array_map(function (PrismTool $tool): array {
            $properties = $tool->parametersAsArray();

            return array_filter([
                'name' => $tool->name(),
                'description' => $tool->description(),
                'input_schema' => [
                    'type' => 'object',
                    'properties' => $properties === [] ? new \stdClass : $properties,
                    'required' => $tool->requiredParameters(),
                ],
                'cache_control' => self::normalizeCacheControl($tool),
                'strict' => (bool) $tool->providerOptions('strict'),
            ]);
        }, $tools);
    }
}
