<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
class ToolCall implements Arrayable
{
    /**
     * @param  string|array<string, mixed>  $arguments
     * @param  null|array<string, mixed>  $reasoningSummary
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string|array $arguments,
        public readonly ?string $resultId = null,
        public readonly ?string $reasoningId = null,
        public readonly ?array $reasoningSummary = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        if (is_string($this->arguments)) {
            if ($this->arguments === '' || $this->arguments === '0') {
                return [];
            }

            // Sanitize control characters that some providers (e.g. DeepSeek) may include
            // in streamed tool call arguments. Raw 0x00-0x1F / 0x7F bytes are never valid
            // in JSON (RFC 8259) and cause json_decode to throw "Control character error".
            $arguments = preg_replace('/[\x00-\x1F\x7F]/', '', $this->arguments);

            return json_decode(
                $arguments,
                true,
                flags: JSON_THROW_ON_ERROR
            );
        }

        /** @var array<string, mixed> $arguments */
        $arguments = $this->arguments;

        return $arguments;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
            'result_id' => $this->resultId,
            'reasoning_id' => $this->reasoningId,
            'reasoning_summary' => $this->reasoningSummary,
        ];
    }
}
