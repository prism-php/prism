<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Perplexity\Maps;

/**
 * Prism's compatibility mapping for retired Sonar model slugs.
 *
 * The Agent API now calls this control `effort`; HandlesHttpRequests translates
 * the public preset alias. Keep the pre-0.141 tier choices here rather than
 * silently increasing a consumer's spend. These are compatibility choices,
 * not a claim of equivalence with Perplexity's current migration table.
 * Explicit `effort` (or legacy `preset`) overrides the mapping.
 *
 * @link https://docs.perplexity.ai/docs/agent-api/migrate-from-sonar/overview
 */
class PresetMap
{
    /**
     * Historical public preset names retained for compatibility.
     *
     * @var array<int, string>
     */
    public const PRESETS = ['fast', 'low', 'medium', 'high', 'xhigh', 'wide-research'];

    /**
     * Retired Sonar slug to Prism's existing tier choice.
     *
     * @var array<string, string>
     */
    public const SLUGS = [
        'sonar' => 'fast',
        'sonar-pro' => 'low',
        // The slug itself is retired with no direct successor; `low` is the
        // nearest reasoning-capable tier.
        'sonar-reasoning' => 'low',
        'sonar-reasoning-pro' => 'low',
        'sonar-deep-research' => 'medium',
    ];

    /**
     * Is this string a preset the Agent API already understands?
     */
    public static function isPreset(string $model): bool
    {
        return in_array($model, self::PRESETS, true);
    }

    /**
     * Is this a retired Sonar slug we translate on the caller's behalf?
     */
    public static function isRetiredSlug(string $model): bool
    {
        return array_key_exists($model, self::SLUGS);
    }

    /**
     * The preset for a model string, or null when it is a real model id.
     *
     * A value this does not recognise — `openai/gpt-5.6-sol`, say — is passed
     * through as a `model` instead, because the Agent API accepts either and
     * guessing a preset for an unknown string would silently change which
     * model served the request.
     */
    public static function presetFor(string $model): ?string
    {
        if (self::isPreset($model)) {
            return $model;
        }

        return self::SLUGS[$model] ?? null;
    }
}
