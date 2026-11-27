<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

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
            'name' => $tool->name(),
            'description' => $tool->description(),
            ...($tool->hasParameters() || (bool) $tool->providerOptions('strict')) ? [
                'parameters' => $tool->parametersAsSchema(requireAllProperties: true),
            ] : [],
            'strict' => (bool) $tool->providerOptions('strict'),
        ]), $tools);
    }
}
