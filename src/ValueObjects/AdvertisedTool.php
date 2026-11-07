<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Tool;
use stdClass;

/**
 * A tool the model was offered, reduced to what a listener may always see.
 *
 * WHY THIS EXISTS. Telemetry withholds the request when
 * `prism.telemetry.capture_content` is off, which is the default — so a
 * listener is told nothing about the tools. That is right for the tool
 * DEFINITIONS, which carry descriptions, and wrong for the fact that a tool set
 * exists and what shape it has.
 *
 * The cost of not having this was measured by a consumer. Providers cache a
 * prompt PREFIX, and the tool array is part of that prefix, so a span could be
 * used to diff most of what was cached and not all of it: a cache miss caused
 * by the system prompt was diagnosable, and one caused by a skill granting
 * tools mid-turn was invisible. The diagnosis SUCCEEDED on one cause and was
 * silently blind to another, which is worse than not having it — it reads as
 * "the prefix is stable".
 *
 * A name and a digest are not content. They are authored by the application,
 * not by the user and not by the model, which is the line `capture_content`
 * draws. So they travel unconditionally, exactly as {@see ProviderRateLimit}
 * does and for the same reason: the thing is only useful BEFORE you have a
 * problem, and gating it behind a switch nobody turns on in production means it
 * is absent precisely when it is wanted.
 *
 * @implements Arrayable<string, mixed>
 */
readonly class AdvertisedTool implements Arrayable
{
    public function __construct(
        public string $name,
        public string $digest,
    ) {}

    public static function from(Tool $tool): self
    {
        return new self($tool->name(), self::digestOf($tool));
    }

    /**
     * A stable fingerprint of everything the model is told about this tool.
     *
     * NAME, DESCRIPTION AND PARAMETERS, because all three are in the prefix a
     * provider caches. A digest over the name alone would answer "was this tool
     * present", which the name already answers; the question worth asking is
     * "did what this tool claims to do change", and a rewritten description
     * changes the cached prefix without changing anything else observable.
     *
     * NOT AN MCP TRUST PIN. `prism-mcp` also hashes a tool definition, to
     * detect a server changing a description after approval, and G-20
     * invalidated every pin in existence when the reference and the ports were
     * reconciled on an empty map versus an absent description. The two digests
     * answer different questions over different inputs and WILL differ.
     * Comparing one to the other would produce a confident wrong conclusion,
     * which is why this says so here rather than leaving it to be discovered.
     */
    public static function digestOf(Tool $tool): string
    {
        $parameters = $tool->parametersAsArray();

        $canonical = self::canonical([
            'name' => $tool->name(),
            'description' => $tool->description(),
            // AN EMPTY PARAMETER MAP IS `{}`, NOT `[]`, AND PHP CANNOT TELL.
            // `parametersAsArray()` returns [] for a tool that takes no
            // arguments, which json_encode writes as a LIST -- so `parameters`
            // changed JSON TYPE with its contents, and the digest became a
            // PHP-ism that no other language reproduces. That broke the only
            // thing this value is for: a TypeScript service and a PHP service
            // instrumenting one agent disagreed about whether the tool set had
            // changed, silently, and in the direction that reports a change
            // where none happened. A tool with no parameters is also the
            // commonest tool shape there is.
            //
            // Finding F-3 in a new place, and shipped in v0.124.0 because every
            // test asserted PHP against PHP. `prism-parity`'s otel-0023 is the
            // row that now fails when this regresses.
            //
            // The substitution is TOP-LEVEL ONLY and the residue is irreducible:
            // a parameter whose own schema is empty hits the same ambiguity one
            // level down, and a blanket "empty array becomes an object" rule
            // would corrupt genuine empty LISTS -- `required: []` is a list and
            // must stay one.
            'parameters' => $parameters === [] ? new stdClass : $parameters,
        ]);

        // JSON_THROW_ON_ERROR is deliberately absent: telemetry must never
        // throw into a generation. A tool whose schema will not encode gets a
        // digest over the partial output, which is stable for that tool and
        // simply less informative -- an exception here would abort a request
        // that was otherwise fine.
        $encoded = json_encode(
            $canonical,
            JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return 'sha256:'.hash('sha256', is_string($encoded) ? $encoded : '');
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'digest' => $this->digest,
        ];
    }

    /**
     * Sort every map key, at every depth, leaving lists in their order.
     *
     * A digest is only comparable if two languages building the same tool
     * produce the same bytes, and map iteration order is an implementation
     * detail in all three. Sorting makes it not one.
     *
     * LISTS ARE NOT SORTED, and that is the distinction the whole thing turns
     * on: `required: ["b","a"]` is a different JSON Schema from
     * `required: ["a","b"]` to a reader, and reordering it here would make two
     * genuinely different tools share a digest. Only a map's KEY ORDER is
     * meaningless.
     */
    protected static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $mapped = array_map(self::canonical(...), $value);

        if (array_is_list($mapped)) {
            return $mapped;
        }

        ksort($mapped);

        return $mapped;
    }
}
