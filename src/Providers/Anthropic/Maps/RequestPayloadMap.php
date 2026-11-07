<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Maps;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Prism\Prism\Providers\Anthropic\Concerns\ResolvesThinking;
use Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies\AnthropicStructuredStrategy;
use Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies\NativeOutputFormatStructuredStrategy;
use Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies\ToolStructuredStrategy;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Support\RequestPayloadProjection;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\ProviderTool;

/** @internal */
class RequestPayloadMap
{
    use ResolvesThinking;

    /**
     * @param  'text'|'structured'|'stream'  $mode
     * @return array<string, mixed>
     */
    public static function project(TextRequest|StructuredRequest $request, string $mode): array
    {
        RequestPayloadProjection::assertProjectable($request);

        if ($mode === 'structured' && $request instanceof StructuredRequest) {
            $request = clone $request;
            self::createStrategy($request)->appendMessages();

            return self::structured($request, self::buildTools($request), self::resolveThinking($request));
        }

        if ($request instanceof TextRequest) {
            return match ($mode) {
                'text' => self::text($request, self::buildTools($request), self::resolveThinking($request)),
                'stream' => self::stream($request),
                default => throw new InvalidArgumentException('Mode must match the request type'),
            };
        }

        throw new InvalidArgumentException('Mode must match the request type');
    }

    /**
     * @param  array<int|string, mixed>  $tools
     * @return array<string, mixed>
     */
    public static function text(TextRequest $request, array $tools, mixed $thinking): array
    {
        return Arr::whereNotNull([
            'model' => $request->model(),
            'system' => MessageMap::mapSystemMessages($request->systemPrompts()) ?: null,
            'messages' => MessageMap::map($request->messages(), $request->providerOptions()),
            'thinking' => $thinking,
            'max_tokens' => $request->maxTokens() ?? 64000,
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'tools' => $tools ?: null,
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            'mcp_servers' => $request->providerOptions('mcp_servers'),
            'cache_control' => $request->providerOptions('cache_control'),
            // A RAW PASSTHROUGH, deliberately not a typed builder.
            //
            // The edits are DATED identifiers -- `clear_tool_uses_20250919`,
            // `clear_thinking_20251015`, `compact_20260112` -- and the beta
            // header is dated too (`context-management-2025-06-27`). A typed
            // surface would freeze a shape that is going to move, then need
            // deprecating; a passthrough carries all three edits and whatever
            // replaces them, for one line.
            //
            // Reported as #35 with the gap measured rather than described: the
            // beta HEADER was already reachable through
            // providerOptions('anthropic_beta'), and this body is an allowlist,
            // so the request silently never carried the field. A caller could
            // switch the beta on and have nothing happen, with nothing anywhere
            // reporting a problem.
            'context_management' => $request->providerOptions('context_management'),
            'output_config' => $request->providerOptions('effort') !== null
                ? ['effort' => $request->providerOptions('effort')]
                : null,
            'fallbacks' => $request->providerOptions('fallbacks'),
        ]);
    }

    /** @return array<string, mixed> */
    public static function stream(TextRequest $request): array
    {
        return Arr::whereNotNull(['stream' => true, ...self::text($request, self::buildTools($request), self::resolveThinking($request))]);
    }

    /** The sender has already appended the strategy's messages.
     * @param  array<int|string, mixed>  $tools
     * @return array<string, mixed>
     */
    public static function structured(StructuredRequest $request, array $tools, mixed $thinking): array
    {
        $structuredStrategy = self::createStrategy($request);

        $basePayload = Arr::whereNotNull([
            'model' => $request->model(),
            'messages' => MessageMap::map($request->messages(), $request->providerOptions()),
            'system' => MessageMap::mapSystemMessages($request->systemPrompts()) ?: null,
            'thinking' => $thinking,
            'max_tokens' => $request->maxTokens() ?? 64000,
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'tools' => $tools ?: null,
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            'mcp_servers' => $request->providerOptions('mcp_servers'),
            'cache_control' => $request->providerOptions('cache_control'),
            // A RAW PASSTHROUGH, deliberately not a typed builder.
            //
            // The edits are DATED identifiers -- `clear_tool_uses_20250919`,
            // `clear_thinking_20251015`, `compact_20260112` -- and the beta
            // header is dated too (`context-management-2025-06-27`). A typed
            // surface would freeze a shape that is going to move, then need
            // deprecating; a passthrough carries all three edits and whatever
            // replaces them, for one line.
            //
            // Reported as #35 with the gap measured rather than described: the
            // beta HEADER was already reachable through
            // providerOptions('anthropic_beta'), and this body is an allowlist,
            // so the request silently never carried the field. A caller could
            // switch the beta on and have nothing happen, with nothing anywhere
            // reporting a problem.
            'context_management' => $request->providerOptions('context_management'),
            'output_config' => $request->providerOptions('effort') !== null
                ? ['effort' => $request->providerOptions('effort')]
                : null,
            'fallbacks' => $request->providerOptions('fallbacks'),
        ]);

        return $structuredStrategy->mutatePayload($basePayload);
    }

    public static function createStrategy(StructuredRequest $request): AnthropicStructuredStrategy
    {
        return $request->providerOptions('use_tool_calling')
            ? new ToolStructuredStrategy(request: $request)
            : new NativeOutputFormatStructuredStrategy(request: $request);
    }

    /** @return array<int|string, mixed> */
    public static function buildTools(TextRequest|StructuredRequest $request): array
    {
        $tools = ToolMap::map($request->tools());

        if ($request->providerTools() === []) {
            return $tools;
        }

        $providerTools = array_map(
            fn (ProviderTool $tool): array => [
                'type' => $tool->type,
                'name' => $tool->name,
                ...$tool->options,
            ],
            $request->providerTools()
        );

        return array_merge($providerTools, $tools);
    }
}
