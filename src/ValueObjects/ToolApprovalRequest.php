<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Represents a tool approval request.
 * Tracked on AssistantMessage for correlation with tool calls.
 *
 * `argumentsFormat` says how the arguments of the call it belongs to were
 * stored. Requests stored before the marker existed have none, and before
 * 0.152 a call whose arguments could not be parsed was stored as one string
 * under `raw` or `input`. So an unmarked request on a tool that names such a
 * parameter is ambiguous, and it is expired on resume rather than run. Store
 * the marker with the request (toArray() includes it) and read it back with
 * fromArray(). Only Prism sets the marker, on the requests it issues; never
 * add it to a request rebuilt from a row stored without it.
 *
 * @implements Arrayable<string, mixed>
 */
readonly class ToolApprovalRequest implements Arrayable
{
    /**
     * The call's arguments are stored exactly as the model sent them.
     */
    public const ARGUMENTS_AS_SENT = 1;

    public function __construct(
        public string $approvalId,
        public string $toolCallId,
        public ?int $argumentsFormat = null,
    ) {}

    /**
     * Read a stored request back. A marker other than a known integer is read
     * as no marker.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $format = $data['arguments_format'] ?? null;

        return new self(
            approvalId: is_string($data['approval_id'] ?? null) ? $data['approval_id'] : '',
            toolCallId: is_string($data['tool_call_id'] ?? null) ? $data['tool_call_id'] : '',
            argumentsFormat: $format === self::ARGUMENTS_AS_SENT ? $format : null,
        );
    }

    /**
     * Whether the call's arguments are known to be stored as the model sent them.
     */
    public function argumentsAreAsSent(): bool
    {
        return $this->argumentsFormat === self::ARGUMENTS_AS_SENT;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        $data = [
            'approval_id' => $this->approvalId,
            'tool_call_id' => $this->toolCallId,
        ];

        if ($this->argumentsFormat !== null) {
            $data['arguments_format'] = $this->argumentsFormat;
        }

        return $data;
    }
}
