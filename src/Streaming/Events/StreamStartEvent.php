<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;

readonly class StreamStartEvent extends StreamEvent
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        string $id,
        int $timestamp,
        public string $model,           // AI model being used
        public string $provider,        // Provider name (anthropic, openai, etc.)
        public ?array $metadata = null  // Additional provider-specific metadata
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::StreamStart;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'model' => $this->model,
            'provider' => $this->provider,
            'metadata' => $this->metadata,
        ];
    }
}
