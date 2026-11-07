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
        $packed = @inet_pton($address);

        if ($packed === false) {
            throw new PrismUrlRefused('private_address_refused', $host.' is not a valid address, which a guarded fetch will not request.');
        }

        // NAT64 and 6to4 carry an IPv4 address inside an IPv6 one, and that
        // embedded address is where the packet goes: 64:ff9b::a9fe:a9fe IS the
        // metadata endpoint on a NAT64 network. Judge the embedded address. Not
        // refused outright, because a DNS64 resolver hands an IPv6-only host
        // 64:ff9b:: addresses for perfectly public sites.
        foreach (self::EMBEDDING as [$prefix, $bits, $offset]) {
            if (strlen($packed) === 16 && self::inRange($packed, $prefix, $bits)) {
                self::assertPublicAddress((string) inet_ntop(substr($packed, $offset, 4)), $host);

                return;
            }
        }

        // The explicit list is the contract, spelled identically in all three
        // languages (G-89). filter_var stays as a second layer, but it cannot be
        // the only one: its flags do not cover CGNAT, where Alibaba Cloud's
        // metadata endpoint lives, and they vary by PHP version.
        foreach (self::BLOCKED as [$network, $bits]) {
            if (self::inRange($packed, $network, $bits)) {
                throw new PrismUrlRefused('private_address_refused', $host.' is a private or reserved address, which a guarded fetch will not request.');
            }
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new PrismUrlRefused('private_address_refused', $host.' is a private or reserved address, which a guarded fetch will not request.');
        }
    }

    /**
     * Every range a guarded fetch refuses: private, loopback, link-local,
     * shared (CGNAT), documentation, benchmarking, multicast, reserved, and the
     * IPv6 spellings that reach them.
     */
    protected const BLOCKED = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
        ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
        ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24],
        ['224.0.0.0', 4], ['240.0.0.0', 4],
        ['::', 96], ['::ffff:0:0', 96], ['64:ff9b:1::', 48], ['100::', 64],
        ['2001::', 32], ['2001:db8::', 32], ['fc00::', 7], ['fe80::', 10],
        ['fec0::', 10], ['ff00::', 8],
    ];

    /** Prefixes that embed an IPv4 address: [prefix, bits, byte offset]. */
    protected const EMBEDDING = [
        ['64:ff9b::', 96, 12],
        ['2002::', 16, 2],
    ];

    protected static function inRange(string $packed, string $network, int $bits): bool
    {
        $base = (string) inet_pton($network);

        if (strlen($packed) !== strlen($base)) {
            return false;
        }

        $bytes = intdiv($bits, 8);

        if (strncmp($packed, $base, $bytes) !== 0) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }
}
