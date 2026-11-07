<?php

declare(strict_types=1);

namespace Tests\Fixtures\Concurrency;

use Prism\Prism\Providers\Anthropic\Handlers\Text;
use Prism\Prism\Providers\OpenAI\Handlers\Stream;
use Prism\Prism\Providers\OpenAI\Maps\ToolCallMap;
use Prism\Prism\ValueObjects\ToolCall;
use ReflectionClass;
use ReflectionMethod;

class ProviderToolCalls
{
    /** @return array<string, array{call: ToolCall, normalizerOnly: bool}> */
    public static function cases(): array
    {
        $rows = [];
        $nested = [['id' => 'call', 'function' => ['name' => 'probe', 'arguments' => '{"input":"v"}']]];
        $flat = [['id' => 'call', 'name' => 'probe', 'arguments' => '{"input":"v"}']];
        foreach (['DeepSeek', 'Azure', 'Qwen', 'OpenRouter', 'Requesty'] as $provider) {
            $rows[$provider.'/text'] = self::map('Prism\\Prism\\Providers\\'.$provider.'\\Maps\\ToolCallMap', 'map', [$nested]);
        }
        foreach (['DeepSeek', 'Azure', 'Qwen', 'XAI', 'Z', 'Groq', 'Mistral'] as $provider) {
            $rows[$provider.'/stream'] = self::map('Prism\\Prism\\Providers\\'.$provider.'\\Handlers\\Stream', 'mapToolCalls', [$flat]);
        }
        foreach (['XAI', 'Z', 'Groq', 'Mistral'] as $provider) {
            $rows[$provider.'/text'] = self::map('Prism\\Prism\\Providers\\'.$provider.'\\Handlers\\Text', 'mapToolCalls', [$nested]);
        }
        foreach (['OpenRouter', 'Requesty'] as $provider) {
            $rows[$provider.'/stream'] = self::map('Prism\\Prism\\Providers\\'.$provider.'\\Handlers\\Stream', 'mapToolCalls', [$nested]);
        }
        $rows['OpenAI/chat-text'] = self::map(\Prism\Prism\Providers\OpenAI\Handlers\ChatCompletions\Text::class, 'mapToolCalls', [$nested]);
        $rows['OpenAI/chat-stream'] = self::map(\Prism\Prism\Providers\OpenAI\Handlers\ChatCompletions\Stream::class, 'mapToolCalls', [$flat]);
        $response = [['id' => 'call', 'name' => 'probe', 'arguments' => '{"input":"v"}', 'call_id' => 'result']];
        $rows['OpenAI/responses-text'] = self::map(ToolCallMap::class, 'map', [$response, []]);
        $rows['OpenAI/responses-reasoning'] = self::map(ToolCallMap::class, 'map', [$response, [['id' => 'reason', 'summary' => []]]]);
        $rows['OpenAI/responses-stream'] = self::map(Stream::class, 'mapToolCalls', [$response]);
        $gemini = [['functionCall' => ['name' => 'probe', 'args' => ['input' => 'v']]]];
        foreach (['Gemini', 'Vertex'] as $provider) {
            $rows[$provider.'/text-structured'] = self::map(\Prism\Prism\Providers\Gemini\Maps\ToolCallMap::class, 'map', [$gemini]);
            $rows[$provider.'/stream'] = self::map('Prism\\Prism\\Providers\\'.$provider.'\\Handlers\\Stream', 'mapToolCall', [$flat[0]]);
        }
        $rows['Anthropic/text'] = self::map(Text::class, 'extractToolCalls', [['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'call', 'name' => 'probe', 'input' => ['input' => 'v']]]]]);
        // Stream constructs ToolCall from this normalizer's output; this does not simulate a whole stream.
        $rows['Anthropic/stream-normalizer'] = self::map(\Prism\Prism\Providers\Anthropic\Handlers\Stream::class, 'normalizeToolInput', ['{"input":"v"}'], true);
        $ollama = [['id' => 'call', 'function' => ['name' => 'probe', 'arguments' => ['input' => 'v']]]];
        $rows['Ollama/text'] = self::map(\Prism\Prism\Providers\Ollama\Handlers\Text::class, 'mapToolCalls', [$ollama]);
        $stream = (new ReflectionClass(\Prism\Prism\Providers\Ollama\Handlers\Stream::class))->newInstanceWithoutConstructor();
        $extracted = (new ReflectionMethod($stream, 'extractToolCalls'))->invoke($stream, ['message' => ['tool_calls' => $ollama]], []);
        $rows['Ollama/stream'] = self::map(\Prism\Prism\Providers\Ollama\Handlers\Stream::class, 'mapToolCalls', [$extracted]);

        return $rows;
    }

    /** @return array{call: ToolCall, normalizerOnly: bool} */
    public static function map(string $class, string $method, array $arguments, bool $normalizerOnly = false): array
    {
        $reflection = new ReflectionMethod($class, $method);
        $target = $reflection->isStatic() ? null : (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $mapped = $reflection->invokeArgs($target, $arguments);
        $call = $normalizerOnly ? new ToolCall('call', 'probe', $mapped)
            : ($mapped instanceof ToolCall ? $mapped : array_values($mapped)[0]);

        return ['call' => $call, 'normalizerOnly' => $normalizerOnly];
    }
}
