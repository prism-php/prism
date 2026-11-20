<?php

declare(strict_types=1);

namespace Prism\Prism\Exceptions;

/**
 * A guarded fetch refused a URL, and says WHY in a machine-readable code.
 *
 * The sentence is for a human and is explicitly outside the contract: reword
 * it freely. The CODE is the contract — a consumer branches on it to decide
 * whether to widen an allow-list, surface a hard stop, or report an SSRF
 * attempt — so never change one without meaning to.
 *
 * Without a code every caller is left matching on prose, and prose cannot be
 * compared across a port either: the three languages word these differently on
 * purpose, and a corpus over the wording would hold each of them to a
 * translation and redden on an improvement that changed nothing.
 *
 * The spellings are `prism-browser`'s, deliberately. `private_address_refused`
 * is the same refusal that package raises for the same reason, and a consumer
 * who fetches a URL through either should not have to learn two names for one
 * finding. See prism-parity `docs/decisions/0004-error-codes.md`.
 *
 * A METHOD rather than a property, for the same reason as `BrowserRefused`:
 * `Exception::$code` is an untyped int, and a subclass cannot redeclare it as
 * a string.
 *
 * Extends `PrismException`, so a caller who already catches that keeps
 * catching this and does not have to learn a new type to stay working.
 */
class PrismUrlRefused extends PrismException
{
    public function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    /**
     * One of: `scheme_not_allowed`, `private_address_refused`,
     * `host_did_not_resolve`, `redirect_refused`, `too_many_redirects`.
     */
    public function code(): string
    {
        return $this->errorCode;
    }
}
