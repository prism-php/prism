<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Support;

use stdClass;

/**
 * Helpers for the ordered `content` array of an Anthropic message, which Prism
 * stores verbatim as `additionalContent['content_blocks']` and replays as-is.
 */
class ContentBlocks
{
    public const THINKING_TYPES = ['thinking', 'redacted_thinking'];

    /**
     * Block types that never carry `cache_control` on replay.
     */
    public const UNCACHEABLE_TYPES = ['thinking', 'redacted_thinking', 'fallback', 'connector_text'];

    /**
     * Block types dropped from the replayed turn when they precede the final
     * `fallback` block (server-side fallback echo rules).
     */
    public const DROPPED_BEFORE_FALLBACK = ['thinking', 'redacted_thinking', 'connector_text', 'tool_use'];

    /**
     * Server-executed tool use, answered by a `*_tool_result` block in the same turn.
     */
    public const SERVER_TOOL_USE_TYPES = ['server_tool_use', 'mcp_tool_use'];

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public static function lastFallbackIndex(array $blocks): ?int
    {
        $lastIndex = null;

        foreach (array_values($blocks) as $index => $block) {
            if (($block['type'] ?? null) === 'fallback') {
                $lastIndex = $index;
            }
        }

        return $lastIndex;
    }

    /**
     * Blocks produced by the model that served the response: everything after
     * the final `fallback` block, or every block when there is none.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function servingBlocks(array $blocks): array
    {
        $blocks = array_values($blocks);
        $lastFallback = self::lastFallbackIndex($blocks);

        return $lastFallback === null ? $blocks : array_slice($blocks, $lastFallback + 1);
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function thinkingBlocks(array $blocks): array
    {
        return array_values(array_filter(
            $blocks,
            fn (array $block): bool => in_array($block['type'] ?? null, self::THINKING_TYPES, true)
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array{from: mixed, to: mixed}>
     */
    public static function fallbacks(array $blocks): array
    {
        $fallbacks = [];

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'fallback') {
                $fallbacks[] = [
                    'from' => data_get($block, 'from.model'),
                    'to' => data_get($block, 'to.model'),
                ];
            }
        }

        return $fallbacks;
    }

    /**
     * Applies the server-side fallback echo projection without reordering:
     * `fallback` blocks, text and everything after the final `fallback` block
     * are kept; thinking, redacted thinking, connector text, client tool use
     * and unpaired server tool use before it are dropped.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function forReplay(array $blocks): array
    {
        $blocks = array_values($blocks);
        $lastFallback = self::lastFallbackIndex($blocks);
        $resultIds = self::serverToolResultIds($blocks);

        $replayed = [];

        foreach ($blocks as $index => $block) {
            if ($lastFallback !== null && $index < $lastFallback && self::isDroppedBeforeFallback($block, $resultIds)) {
                continue;
            }

            $replayed[] = self::normalizeForReplay($block);
        }

        return $replayed;
    }

    /**
     * Replay-safe content for a turn that did not end in `tool_use`. Its client
     * tool calls are never executed, so `tool_use` blocks are dropped (they
     * would replay without a `tool_result`), and so is server tool use without
     * its result, unless the turn paused to let the server tool continue.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function withoutUnansweredToolUse(array $blocks, bool $keepServerToolUse = false): array
    {
        $resultIds = self::serverToolResultIds($blocks);

        return array_values(array_filter(
            $blocks,
            fn (array $block): bool => ! self::isUnansweredToolUse($block, $resultIds, $keepServerToolUse)
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $cacheControl
     * @return array<int, array<string, mixed>>
     */
    public static function withCacheControl(array $blocks, array $cacheControl): array
    {
        for ($index = count($blocks) - 1; $index >= 0; $index--) {
            if (! in_array($blocks[$index]['type'] ?? null, self::UNCACHEABLE_TYPES, true)) {
                $blocks[$index]['cache_control'] = $cacheControl;

                break;
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<int, string>  $serverToolResultIds
     */
    protected static function isDroppedBeforeFallback(array $block, array $serverToolResultIds): bool
    {
        $type = $block['type'] ?? null;

        if (in_array($type, self::DROPPED_BEFORE_FALLBACK, true)) {
            return true;
        }

        return $type === 'server_tool_use'
            && ! in_array($block['id'] ?? null, $serverToolResultIds, true);
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<int, string>  $serverToolResultIds
     */
    protected static function isUnansweredToolUse(array $block, array $serverToolResultIds, bool $keepServerToolUse): bool
    {
        $type = $block['type'] ?? null;

        if ($type === 'tool_use') {
            return true;
        }

        return ! $keepServerToolUse
            && in_array($type, self::SERVER_TOOL_USE_TYPES, true)
            && ! in_array($block['id'] ?? null, $serverToolResultIds, true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, string>
     */
    protected static function serverToolResultIds(array $blocks): array
    {
        $ids = [];

        foreach ($blocks as $block) {
            if (str_ends_with((string) ($block['type'] ?? ''), '_tool_result') && isset($block['tool_use_id'])) {
                $ids[] = $block['tool_use_id'];
            }
        }

        return $ids;
    }

    /**
     * JSON objects decode to PHP arrays, so an empty tool input would be
     * re-encoded as `[]`. Restore it as an object.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    protected static function normalizeForReplay(array $block): array
    {
        if (array_key_exists('input', $block) && $block['input'] === []) {
            $block['input'] = new stdClass;
        }

        return $block;
    }
}
