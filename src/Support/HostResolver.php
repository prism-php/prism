<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

/**
 * Resolves a hostname to the addresses a request to it would actually reach.
 *
 * It is an interface so a guard can be tested without real DNS -- and so an
 * application whose resolution differs from the host's (a service mesh, a
 * split-horizon resolver) can bind its own rather than be silently wrong.
 */
interface HostResolver
{
    /**
     * Every A and AAAA address for this host, or an empty list if none.
     *
     * An empty list must be treated as "could not be verified", never as
     * "nothing to check": a host that does not resolve is not a public host.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
