<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\ValueObjects\Usage;

readonly class StreamEndEvent extends StreamEvent
{
    public function __construct(
        string $id,
        int $timestamp,
        public FinishReason $finishReason,  // Why stream ended
        public ?Usage $usage = null,        // Token usage information
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::StreamEnd;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'finish_reason' => $this->finishReason->name,
            'usage' => $this->usage instanceof \Prism\Prism\ValueObjects\Usage ? [
                'prompt_tokens' => $this->usage->promptTokens,
                'completion_tokens' => $this->usage->completionTokens,
                'cache_write_input_tokens' => $this->usage->cacheWriteInputTokens,
                'cache_read_input_tokens' => $this->usage->cacheReadInputTokens,
                'thought_tokens' => $this->usage->thoughtTokens,
            ] : null,
        ];
    }
}
