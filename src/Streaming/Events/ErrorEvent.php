<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use JsonSerializable;
use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\ValueObjects\StreamFailureUsage;

readonly class ErrorEvent extends StreamEvent implements JsonSerializable
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
        public ?StreamFailureUsage $failureUsage = null,
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::Error;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $public = [];
        foreach ((array) $this as $key => $value) {
            if ($key !== 'failureUsage' && ! str_contains((string) $key, "\0")) {
                $public[$key] = $value;
            }
        }

        return [...$public, ...($this->failureUsage instanceof StreamFailureUsage ? ['failure_usage' => $this->failureUsage->toArray()] : [])];
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
            ...($this->failureUsage instanceof StreamFailureUsage ? ['failure_usage' => $this->failureUsage->toArray()] : []),
        ];
    }
}
