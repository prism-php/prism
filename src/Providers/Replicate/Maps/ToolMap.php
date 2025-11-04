<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Maps;

use Prism\Prism\Tool;

class ToolMap
{
    /**
     * Convert Prism tools to JSON schema for system prompt.
     *
     * @param  Tool[]  $tools
     * @return string JSON-encoded tool definitions
     */
    public static function map(array $tools): string
    {
        if ($tools === []) {
            return '';
        }

        $toolDefinitions = array_map(fn (Tool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => [
                'type' => 'object',
                'properties' => $tool->parametersAsArray(),
                'required' => $tool->requiredParameters(),
            ],
        ], $tools);

        return json_encode(['tools' => $toolDefinitions], JSON_PRETTY_PRINT) ?: '';
    }

    /**
     * Generate system prompt with tool instructions.
     *
     * @param  Tool[]  $tools
     * @return string System prompt with tool definitions
     */
    public static function buildSystemPrompt(array $tools): string
    {
        if ($tools === []) {
            return '';
        }

        $toolsJson = self::map($tools);

        return <<<PROMPT
You are a helpful assistant with access to the following tools:

{$toolsJson}

When you need to use a tool, respond ONLY with valid JSON in this exact format:
{
  "tool_calls": [
    {
      "id": "call_<random_string>",
      "name": "tool_name",
      "arguments": {
        "param1": "value1"
      }
    }
  ]
}

Important rules:
1. Generate a unique ID for each tool call (start with "call_")
2. Only call tools when necessary to answer the user's question
3. You can call multiple tools at once by including them in the tool_calls array
4. After receiving tool results, provide your final answer as plain text (not JSON)
5. If no tools are needed, respond directly with plain text

PROMPT;
    }
}
