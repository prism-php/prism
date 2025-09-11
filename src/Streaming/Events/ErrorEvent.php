<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;

readonly class ErrorEvent extends StreamEvent
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        string $id,
        int $timestamp,
        public string $errorType,       // Type of error (rate_limit, validation, etc.)
        public string $message,         // Error message
        public bool $recoverable,       // Whether stream can continue
        public ?array $metadata = null, // Additional error context
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::Error;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'error_type' => $this->errorType,
            'message' => $this->message,
            'recoverable' => $this->recoverable,
            'metadata' => $this->metadata,
        ];
    }
}
