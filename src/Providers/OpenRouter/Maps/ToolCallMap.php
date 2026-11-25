<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Maps;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\ToolCall;

class ToolCallMap
{
    /**
     * @param  array<int, mixed>  $toolCalls
     * @return array<int, ToolCall>
     */
    public static function map(array $toolCalls): array
    {
        return array_map(function (array $toolCall): ToolCall {
            $rawArguments = (string) $toolCall['function']['arguments'];

            if ($rawArguments === '') {
                return new ToolCall(
                    id: $toolCall['id'],
                    name: $toolCall['function']['name'],
                    arguments: [],
                );
            }

            try {
                $arguments = json_decode(
                    $rawArguments,
                    true,
                    flags: JSON_THROW_ON_ERROR
                );
            } catch (\JsonException) {
                throw PrismException::providerResponseError(
                    'OpenRouter Error: Invalid tool call arguments JSON'
                );
            }

            if (! is_array($arguments)) {
                throw PrismException::providerResponseError(
                    'OpenRouter Error: Tool call arguments must be a JSON object'
                );
            }

            return new ToolCall(
                id: $toolCall['id'],
                name: $toolCall['function']['name'],
                arguments: $arguments,
            );
        }, $toolCalls);
    }
}
