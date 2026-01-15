<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Support\Facades\Context;
use Prism\Prism\Prism;

trait HasTelemetryContext
{
    /** @var array<string, mixed> */
    protected array $telemetryContext = [];

    /**
     * Add context metadata for telemetry tracking.
     *
     * This metadata is included in telemetry spans and can be used
     * for filtering, grouping, and analysis in observability platforms.
     *
     * Common keys: user_id, user_email, session_id, environment, etc.
     *
     * @param  array<string, mixed>  $context
     */
    public function withTelemetryContext(array $context): static
    {
        $this->telemetryContext = array_merge($this->telemetryContext, $context);

        return $this;
    }

    /**
     * Set user context for telemetry tracking.
     *
     * @param  string|int|null  $userId  User identifier
     * @param  string|null  $email  User email (optional)
     */
    public function forUser(string|int|null $userId, ?string $email = null): static
    {
        return $this->withTelemetryContext(array_filter([
            'user_id' => $userId !== null ? (string) $userId : null,
            'user_email' => $email,
        ], fn (?string $v): bool => $v !== null));
    }

    /**
     * Set conversation/chat session context for telemetry tracking.
     *
     * The session ID should represent the logical conversation thread,
     * NOT the Laravel HTTP session. This allows Phoenix/Arize to group
     * all messages in a conversation together.
     *
     * @param  string|int  $conversationId  The conversation/chat thread ID
     */
    public function forConversation(string|int $conversationId): static
    {
        return $this->withTelemetryContext([
            'session_id' => (string) $conversationId,
        ]);
    }

    /**
     * Set the agent/bot identifier for telemetry tracking.
     *
     * Use this when you have multiple AI agents in your application
     * to differentiate and group telemetry by agent type.
     *
     * @param  string  $agent  The agent identifier (e.g., "support-bot", "code-assistant")
     */
    public function forAgent(string $agent): static
    {
        return $this->withTelemetryContext([
            'agent' => $agent,
        ]);
    }

    /**
     * Set tags for telemetry filtering.
     *
     * Tags are key-value pairs useful for filtering and grouping in observability platforms.
     *
     * @param  array<string, string>  $tags
     */
    public function withTelemetryTags(array $tags): static
    {
        return $this->withTelemetryContext(['tags' => $tags]);
    }

    /**
     * Push telemetry context to Laravel's Context facade.
     *
     * Uses hidden context so telemetry metadata doesn't leak into logs.
     * This makes the context available to SpanCollector without
     * needing to pass it through events.
     */
    protected function pushTelemetryContext(): void
    {
        $context = array_merge(
            Prism::getDefaultTelemetryContext(),
            $this->telemetryContext
        );

        if ($context !== []) {
            // Use hidden context to avoid leaking telemetry data into logs
            Context::addHidden('prism.telemetry.metadata', $context);
        }
    }
}
