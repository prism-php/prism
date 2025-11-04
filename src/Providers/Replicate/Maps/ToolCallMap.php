<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Maps;

use Prism\Prism\ValueObjects\ToolCall;
use Throwable;

class ToolCallMap
{
    /**
     * Parse model output and extract tool calls.
     *
     * @param  mixed  $output  Model output (array or string)
     * @return ToolCall[]
     */
    public static function map(mixed $output): array
    {
        // Convert array output to string
        if (is_array($output)) {
            $output = implode('', $output);
        }

        if (! is_string($output)) {
            return [];
        }

        // Try to extract JSON from output
        $json = self::extractJson($output);

        if ($json === null) {
            return [];
        }

        // Check for tool_calls array
        $toolCalls = $json['tool_calls'] ?? [];

        if (! is_array($toolCalls)) {
            return [];
        }

        // Map to ToolCall objects
        return array_map(
            fn (array $call): ToolCall => new ToolCall(
                id: $call['id'] ?? self::generateId(),
                name: $call['name'],
                arguments: $call['arguments'] ?? []
            ),
            $toolCalls
        );
    }

    /**
     * Check if output contains tool calls.
     */
    public static function hasToolCalls(mixed $output): bool
    {
        if (is_array($output)) {
            $output = implode('', $output);
        }

        if (! is_string($output)) {
            return false;
        }

        // Look for tool call markers
        return str_contains($output, '"tool_calls"')
            || str_contains($output, 'tool_calls');
    }

    /**
     * Extract JSON from model output.
     *
     * Handles cases where model wraps JSON in markdown or adds text.
     *
     * @return array<string, mixed>|null
     */
    protected static function extractJson(string $output): ?array
    {
        $output = trim($output);

        // Remove markdown code blocks if present
        $output = preg_replace('/^```json\s*/', '', $output);
        $output = preg_replace('/```\s*$/', '', (string) $output);
        $output = trim((string) $output);

        // Try to find JSON object
        if (preg_match('/\{.*"tool_calls".*\}/s', $output, $matches)) {
            $json = $matches[0];

            try {
                return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return null;
            }
        }

        // Try parsing entire output as JSON
        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            if (isset($decoded['tool_calls'])) {
                return $decoded;
            }
        } catch (Throwable) {
            // Not valid JSON
        }

        return null;
    }

    /**
     * Generate a unique tool call ID.
     */
    protected static function generateId(): string
    {
        return 'call_'.bin2hex(random_bytes(8));
    }
}
