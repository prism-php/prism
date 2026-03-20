<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Prism\Prism\ValueObjects\ProviderToolCall;

trait ExtractsProviderToolCalls
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<int, ProviderToolCall>
     */
    protected function extractProviderToolCalls(array $data): array
    {
        $providerToolCalls = [];
        $contents = data_get($data, 'content', []);

        foreach ($contents as $content) {
            $type = data_get($content, 'type', '');

            if ($type === 'server_tool_use') {
                $providerToolCalls[] = new ProviderToolCall(
                    id: data_get($content, 'id', ''),
                    type: data_get($content, 'name', ''),
                    status: 'completed',
                    data: $content,
                );
            }

            if (str_ends_with((string) $type, '_tool_result')) {
                $providerToolCalls[] = new ProviderToolCall(
                    id: data_get($content, 'tool_use_id', ''),
                    type: $type,
                    status: 'result_received',
                    data: $content,
                );
            }
        }

        return $providerToolCalls;
    }
}
