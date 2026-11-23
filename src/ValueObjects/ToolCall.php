<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use JsonException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Support\Json;
use Prism\Prism\Support\JsonMap;
use stdClass;

/**
 * @implements Arrayable<string, mixed>
 */
class ToolCall implements Arrayable
{
    /**
     * The deepest nesting accepted. A provider wraps the arguments in its own
     * request envelope, and the request is encoded with a depth limit of 512,
     * so the limit leaves room for the deepest envelope.
     */
    protected const MAX_ARGUMENT_DEPTH = 500;

    /**
     * What a record holds in place of unusable arguments: text that is refused
     * if the record is ever read back into a call.
     */
    protected const UNUSABLE_ARGUMENTS_RECORD = ']';

    /** @var string|array<string, mixed> */
    public readonly string|array $arguments;

    /**
     * @param  mixed  $arguments  A JSON string or a map. A provider can send
     *                            anything else, which is kept in a form that
     *                            is refused rather than read as no arguments.
     * @param  null|array<string, mixed>  $reasoningSummary
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        mixed $arguments,
        public readonly ?string $resultId = null,
        public readonly ?string $reasoningId = null,
        public readonly ?array $reasoningSummary = null,
    ) {
        $this->arguments = self::storableArguments($arguments);
    }

    /**
     * A provider's value as this class stores it. Null means none were given.
     * Any other value that is neither text nor a map is held as a one-item
     * list, the one form that is refused whatever the value was: `0` and
     * `false` must not turn into "no arguments" on the way in.
     *
     * @return string|array<string, mixed>
     */
    public static function storableArguments(mixed $arguments): string|array
    {
        if (is_string($arguments)) {
            return $arguments;
        }

        if (is_array($arguments)) {
            /** @var array<string, mixed> $arguments */
            return $arguments;
        }

        if ($arguments === null) {
            return '';
        }

        return json_encode([$arguments], JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[null]';
    }

    /**
     * The accumulated text once a streamed fragment is added. A fragment that
     * is not text makes the whole value unreadable, wherever it arrived: a
     * leading `]` can never become valid JSON, whatever is appended after it.
     */
    public static function appendArgumentFragment(string $text, mixed $fragment): string
    {
        if ($fragment === null) {
            return $text;
        }

        return is_string($fragment) ? $text.$fragment : ']'.$text;
    }

    /**
     * The arguments as PHP sees them: associative arrays all the way down.
     *
     * This is what every caller INSIDE Prism gets — tool handlers, approval
     * callbacks, `handle(...$args)`. It is deliberately unchanged by the
     * container-type work: a handler typed `array $filter` must keep receiving
     * `[]` for a `{}` the model sent, or fixing the wire would break the very
     * tools the wire exists to call.
     *
     * An empty string, `0`, `null`, `[]` and `{}` mean none were given. Anything
     * else that is not a map of named values is refused rather than read as no
     * arguments, so a tool is never run on an empty set the model did not send.
     *
     * @return array<string, mixed>
     *
     * @throws PrismException
     */
    public function arguments(): array
    {
        $failure = $this->argumentsFailure();

        if ($failure instanceof PrismException) {
            throw $failure;
        }

        if (is_string($this->arguments)) {
            $decoded = $this->decodeArguments();

            return is_array($decoded) ? $decoded : [];
        }

        /** @var array<string, mixed> $arguments */
        $arguments = $this->arguments;

        return $arguments;
    }

    /**
     * Whether the model supplied any arguments at all.
     *
     * Mirrors Tool::hasParameters(). A provider that OMITS the arguments key
     * for a no-argument call asks this, so that no send site has to reach for
     * the raw array and decide the container type for itself.
     */
    public function hasArguments(): bool
    {
        return $this->arguments() !== [];
    }

    /**
     * Whether the model's arguments are unusable: not JSON, or not a map of
     * named values. Such a call must not reach an approval check or a handler.
     */
    public function hasMalformedArguments(): bool
    {
        return $this->argumentsFailure() instanceof PrismException;
    }

    /**
     * Why the arguments are unusable, or null when they are fine. A JSON
     * `null` and an empty string both mean the model supplied none.
     *
     * Arguments are admitted only if they survive both decodes and the encode
     * a follow-up request uses, so that a call which ran can always be sent
     * back.
     */
    public function argumentsFailure(): ?PrismException
    {
        try {
            $decoded = is_string($this->arguments) ? $this->decodeArguments() : $this->arguments;

            if (is_string($this->arguments)) {
                $this->decodeArguments(preservingContainerTypes: true);
            }
        } catch (PrismException $failure) {
            return $failure;
        }

        if ($decoded === null) {
            return null;
        }

        // An integer key would bind to a handler parameter by position.
        if (! is_array($decoded) || array_filter(array_keys($decoded), is_int(...)) !== []) {
            return PrismException::toolCallArgumentsNotAnObject($this->name);
        }

        return self::canBeSentBack($decoded)
            ? null
            : PrismException::malformedToolCallArguments($this->name, new JsonException('The value cannot be encoded.'));
    }

    /**
     * The arguments for a result record: empty when they are unusable, so that
     * nothing downstream reads a value the model did not validly send.
     *
     * @return array<string, mixed>
     */
    public function argumentsForResult(): array
    {
        return $this->hasMalformedArguments() ? [] : $this->arguments();
    }

    /**
     * This call with unusable arguments replaced by none, for sending it back
     * once a tool result has reported the failure. A valid call is unchanged.
     */
    public function withoutMalformedArguments(): self
    {
        if (! $this->hasMalformedArguments()) {
            return $this;
        }

        return new self($this->id, $this->name, [], $this->resultId, $this->reasoningId, $this->reasoningSummary);
    }

    /**
     * What a stream handler stores for an accumulated argument string: the
     * decoded map, or the original text when it is not one, which keeps the
     * call detectable by hasMalformedArguments().
     *
     * @return string|array<string, mixed>
     */
    public static function fromStreamedArguments(string $arguments): string|array
    {
        $decoded = json_decode($arguments, true);

        return is_array($decoded) ? $decoded : $arguments;
    }

    /**
     * The arguments as a JSON OBJECT, in the container types the MODEL sent.
     *
     * Every provider message map goes through here, and it differs from
     * `arguments()` in two ways that both matter on the wire:
     *
     *  - an empty argument set is `{}`, never `[]`, which is what the
     *    `?: (object) []` guard copied into each message map used to do — and
     *    what the maps that never got the guard did not;
     *  - a `{}` NESTED anywhere inside survives the round trip, because the
     *    decode preserves it rather than a rule downstream guessing which keys
     *    were maps. `{"filter":{}}` came back out as `{"filter":[]}` before
     *    this, and went to the provider that way on the next turn.
     *
     * The second only works when the provider handed us the arguments as a
     * raw string. A provider that decodes them itself before building the
     * value object has already thrown the distinction away — which is why
     * ToolCallMaps pass the string through.
     */
    public function argumentsAsObject(): stdClass
    {
        $failure = $this->argumentsFailure();

        if ($failure instanceof PrismException) {
            throw $failure;
        }

        $decoded = is_string($this->arguments)
            ? $this->decodeArguments(preservingContainerTypes: true)
            : $this->arguments();

        if ($decoded instanceof stdClass) {
            return $decoded;
        }

        if (! is_array($decoded)) {
            return new stdClass;
        }

        /** @var array<string, mixed> $decoded */
        return JsonMap::of($decoded);
    }

    /**
     * The same object, encoded — for the providers that carry the arguments as
     * a JSON string rather than inline.
     */
    public function argumentsAsJson(): string
    {
        return json_encode($this->argumentsAsObject(), JSON_THROW_ON_ERROR);
    }

    /**
     * The arguments as text for a provider field that carries them as written:
     * the original text when it is valid JSON naming values, otherwise the
     * same value encoded, and `{}` for no arguments or unusable ones.
     */
    public function replayableArgumentsText(): string
    {
        if (is_string($this->arguments) && ! $this->hasMalformedArguments() && $this->arguments() !== []) {
            try {
                json_decode($this->arguments, flags: JSON_THROW_ON_ERROR);

                return $this->arguments;
            } catch (JsonException) {
                // Valid only after repair, so it is sent encoded.
            }
        }

        return $this->withoutMalformedArguments()->argumentsAsJson();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        $malformed = $this->hasMalformedArguments();

        return [
            'id' => $this->id,
            'name' => $this->name,
            // A map when the provider gave us one, and still the original
            // string when it gave us that — but never a JSON list, which is
            // what an empty argument set would otherwise serialise as.
            'arguments' => match (true) {
                $malformed => self::UNUSABLE_ARGUMENTS_RECORD,
                is_array($this->arguments) => JsonMap::of($this->arguments),
                default => $this->arguments,
            },
            ...$malformed ? ['arguments_malformed' => true] : [],
            'result_id' => $this->resultId,
            'reasoning_id' => $this->reasoningId,
            'reasoning_summary' => $this->reasoningSummary,
        ];
    }

    /**
     * Whether a decoded value survives the encode a follow-up request uses.
     * Walked with a stack, so deep input cannot overflow the call stack.
     *
     * @param  array<array-key, mixed>  $decoded
     */
    protected static function canBeSentBack(array $decoded): bool
    {
        $pending = [[$decoded, 1]];

        while ($pending !== []) {
            [$value, $depth] = array_pop($pending);

            if (is_float($value) && ! is_finite($value)) {
                return false;
            }

            if (! is_array($value)) {
                continue;
            }

            if ($depth > self::MAX_ARGUMENT_DEPTH) {
                return false;
            }

            foreach ($value as $key => $item) {
                // An object cannot hold a key starting with NUL.
                if (is_string($key) && str_starts_with($key, "\0")) {
                    return false;
                }

                $pending[] = [$item, $depth + 1];
            }
        }

        try {
            /** @var array<string, mixed> $decoded */
            json_encode(JsonMap::of($decoded), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return true;
    }

    /**
     * @throws PrismException
     */
    protected function decodeArguments(bool $preservingContainerTypes = false): mixed
    {
        if (! is_string($this->arguments) || $this->arguments === '' || $this->arguments === '0') {
            return [];
        }

        try {
            return Json::decode($this->arguments, $preservingContainerTypes);
        } catch (JsonException) {
            // Some providers (e.g. DeepSeek when streaming) emit raw control
            // characters inside string values, which RFC 8259 requires to be
            // escaped. Escape them in place — rather than stripping them, which
            // would corrupt intentional newlines/tabs — and decode again.
            try {
                return Json::decode(
                    self::escapeControlCharactersInStrings($this->arguments),
                    $preservingContainerTypes
                );
            } catch (JsonException $e) {
                throw PrismException::malformedToolCallArguments($this->name, $e);
            }
        }
    }

    /**
     * Escape raw control characters (0x00–0x1F) that appear inside JSON string
     * literals with their JSON escape sequences. Anything else is left as it
     * is, so that the decode refuses it: a control character outside a string,
     * or straight after a backslash, has no value it can be repaired to.
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
                $result .= $inString && ! $escaped
                    ? match ($char) {
                        "\x08" => '\b',
                        "\x09" => '\t',
                        "\x0A" => '\n',
                        "\x0C" => '\f',
                        "\x0D" => '\r',
                        default => sprintf('\u%04x', $ord),
                    }
                : $char;

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
