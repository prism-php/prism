<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\DeepSeek\Maps;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Tool;

class ToolMap
{
    /**
     * @param  Tool[]  $tools
     * @return array<string, mixed>
     */
    public static function Map(array $tools): array
    {
        return array_map(fn (Tool $tool): array => array_filter([
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $tool->parameters(),
                    'required' => $tool->requiredParameters(),
                ],
            ],
            'strict' => data_get($tool->providerMeta(Provider::DeepSeek), 'strict', null),
        ]), $tools);
    }
}
