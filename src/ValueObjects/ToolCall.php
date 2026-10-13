<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use JsonException;

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

            try {
                $decoded = json_decode(
                    $this->arguments,
                    true,
                    flags: JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                // Some providers (e.g. DeepSeek when streaming) emit raw control
                // characters inside string values, which RFC 8259 requires to be
                // escaped. Escape them in place — rather than stripping them, which
                // would corrupt intentional newlines/tabs — and decode again.
                $decoded = json_decode(
                    self::escapeControlCharactersInStrings($this->arguments),
                    true,
                    flags: JSON_THROW_ON_ERROR
                );
            }

            return is_array($decoded) ? $decoded : [];
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

    /**
     * Escape raw control characters (0x00–0x1F) that appear inside JSON string
     * literals with their JSON escape sequences, and drop the ones that appear
     * outside strings where they can never be valid (raw \t, \n and \r between
     * tokens are legal whitespace and are kept).
     */
    protected static function escapeControlCharactersInStrings(string $json): string
    {
        $result = '';
        $inString = false;
        $escaped = false;
        $length = strlen($json);

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            $ord = ord($char);

            if ($ord <= 0x1F) {
                if ($inString) {
                    $result .= match ($char) {
                        "\x08" => '\b',
                        "\x09" => '\t',
                        "\x0A" => '\n',
                        "\x0C" => '\f',
                        "\x0D" => '\r',
                        default => sprintf('\u%04x', $ord),
                    };
                } elseif (in_array($char, ["\t", "\n", "\r"], true)) {
                    $result .= $char;
                }

                $escaped = false;

                continue;
            }

            $result .= $char;

            if ($escaped) {
                $escaped = false;
            } elseif ($inString && $char === '\\') {
                $escaped = true;
            } elseif ($char === '"') {
                $inString = ! $inString;
            }
        }

        return $result;
    }
}
