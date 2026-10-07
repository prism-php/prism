<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

use Prism\Prism\Exceptions\PrismUrlRefused;

/**
 * Refuses a URL that would reach somewhere only the server can reach.
 *
 * WHAT THIS DEFENDS. A URL taken from user input or from model output, fetched
 * server-side, is a request the attacker gets to aim. The interesting targets
 * are not on the internet: a cloud instance's metadata endpoint at
 * 169.254.169.254 hands out role credentials to anything inside the network,
 * and the rest of the private ranges are the services behind the firewall.
 *
 * THREE THINGS HAVE TO BE CHECKED, and checking fewer is the usual mistake:
 *
 * 1. The SCHEME, so `file:///etc/passwd` cannot be passed to an HTTP client
 *    that would happily read it.
 * 2. The literal address, when the host is one.
 * 3. What the NAME RESOLVES TO. An attacker owns their own DNS, so
 *    `evil.example` pointing at 10.0.0.5 defeats a check that only reads the
 *    URL. This is the bypass that makes a URL-string allow-list worthless.
 *
 * And a redirect is a fourth: see Media::fetchPublicUrlContent(), which
 * re-checks every hop rather than letting the client follow one for it.
 *
 * NOT A SANDBOX. A resolution can change between this check and the request
 * (DNS rebinding), which is only closed by pinning the connection to the
 * address that was checked -- something the HTTP client, not this class, would
 * have to do. This raises the cost of the attack from trivial to awkward; it
 * does not make an untrusted URL safe to fetch.
 */
class PublicUrl
{
    /**
     * @throws PrismUrlRefused when the URL is not one a public client could reach
     */
    public static function assert(string $url, HostResolver $resolver): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new PrismUrlRefused('scheme_not_allowed', 'A guarded fetch needs an absolute http or https URL.');
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new PrismUrlRefused('scheme_not_allowed', 'A guarded fetch refuses the scheme '.$parts['scheme'].'; only http and https are fetched.');
        }

        $host = trim($parts['host'], '[]');

        if ($host === '') {
            throw new PrismUrlRefused('scheme_not_allowed', 'A guarded fetch needs an absolute http or https URL.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            self::assertPublicAddress($host, $host);

            return;
        }

        $addresses = $resolver->resolve($host);

        if ($addresses === []) {
            throw new PrismUrlRefused('host_did_not_resolve', $host.' did not resolve to a public address.');
        }

        // EVERY address, not the first. A host that answers with one public
        // and one private address is a host that reaches the private one.
        foreach ($addresses as $address) {
            self::assertPublicAddress($address, $host);
        }
    }

    /**
     * @throws PrismUrlRefused
     */
    protected static function assertPublicAddress(string $address, string $host): void
    {
        // NO_PRIV_RANGE covers 10/8, 172.16/12, 192.168/16 and fc00::/7;
        // NO_RES_RANGE covers loopback, link-local (169.254/16, where the
        // metadata endpoint lives), and the rest of the reserved space.
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new PrismUrlRefused('private_address_refused', $host.' is a private or reserved address, which a guarded fetch will not request.');
        }
    }
}
