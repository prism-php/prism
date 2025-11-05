<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Prism\Prism\ValueObjects\ProviderToolCall;

class ProviderToolCallMap
{
    /**
     * @param  array<int, array<string, mixed>>  $output
     * @return array<int, ProviderToolCall>
     */
    public static function map(array $output): array
    {
        $providerTools = array_filter($output, self::isProviderToolCall(...));

        return array_map(
            fn (array $item): ProviderToolCall => new ProviderToolCall(
                id: data_get($item, 'id'),
                type: data_get($item, 'type'),
                status: data_get($item, 'status'),
                data: $item,
            ),
            $providerTools
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected static function isProviderToolCall(array $item): bool
    {
        $type = data_get($item, 'type', '');

        return is_string($type)
            && str_ends_with($type, '_call')
            && $type !== 'function_call';
    }
}
