<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

use Prism\Prism\Exceptions\PrismUrlRefused;

/**
 * Refuses a PER-CALL provider url that points somewhere only the server can
 * reach.
 *
 * A provider url in configuration is the operator's, and may point anywhere:
 * a model server on localhost or the local network is the common case. A url
 * passed for a single call (`using($provider, $model, ['url' => ...])`) is
 * chosen by whoever builds that call, which in an application is often
 * whoever wrote the request. Wish, 2026-11-24: "users shouldn't be choosing
 * the address for a single call."
 *
 * NO NETWORK. Nothing here resolves a name: a hostname that RESOLVES to a
 * private address is not caught. What is caught is every spelling of an
 * address literal an HTTP client or OS resolver would accept (decimal, octal,
 * hex and shortened IPv4, IPv6 including IPv4-mapped, NAT64 and 6to4), and
 * the documented loopback and metadata names. The address ranges are
 * PublicUrl's, so the two guards cannot drift apart.
 *
 * Spelled identically, rule for rule, in prism-ts (`assertPerCallUrl`) and
 * prism-py (`assert_per_call_url`). No message names the url or the address.
 */
class PerCallUrl
{
    public const PRIVATE_MESSAGE = 'A per-call provider url may not point at a loopback, private, link-local or metadata address. Allow it in the provider config with allow_private_per_call_url.';

    public const SCHEME_MESSAGE = 'A per-call provider url must be an absolute http or https url.';

    /**
     * Loopback names from RFC 6761 and common hosts files, and the documented
     * cloud metadata names. `*.localhost` is matched separately.
     */
    protected const NAMES = [
        'localhost',
        'localhost.localdomain',
        'ip6-localhost',
        'ip6-loopback',
        'metadata',
        'metadata.google.internal',
        'instance-data',
    ];

    /**
     * @throws PrismUrlRefused `scheme_not_allowed` or `private_address_refused`
     */
    public static function assert(string $url): void
    {
        if (preg_match('#\Ahttps?://#i', $url) !== 1) {
            throw new PrismUrlRefused('scheme_not_allowed', self::SCHEME_MESSAGE);
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            throw new PrismUrlRefused('scheme_not_allowed', self::SCHEME_MESSAGE);
        }

        $host = $parts['host'];

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $ascii = function_exists('idn_to_ascii')
                ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)
                : false;

            if (! is_string($ascii)) {
                throw self::refused();
            }

            $host = $ascii;
        }

        $host = rtrim(strtolower($host), '.');

        if ($host === '') {
            throw new PrismUrlRefused('scheme_not_allowed', self::SCHEME_MESSAGE);
        }

        if (str_contains($host, ':')) {
            if (str_contains($host, '%') || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw self::refused();
            }

            if (! PublicUrl::isPublicAddress($host)) {
                throw self::refused();
            }

            return;
        }

        $labels = explode('.', $host);

        if (preg_match('/\A(0x[0-9a-f]*|\d+)\z/', (string) end($labels)) === 1) {
            $address = self::ipv4($labels);

            if ($address === null || ! PublicUrl::isPublicAddress($address)) {
                throw self::refused();
            }

            return;
        }

        if (in_array($host, self::NAMES, true) || str_ends_with($host, '.localhost')) {
            throw self::refused();
        }
    }

    protected static function refused(): PrismUrlRefused
    {
        return new PrismUrlRefused('private_address_refused', self::PRIVATE_MESSAGE);
    }

    /**
     * The dotted quad an OS resolver reads these labels as, or null when they
     * are not a valid IPv4 spelling (which is then refused, not resolved).
     *
     * @param  list<string>  $labels
     */
    protected static function ipv4(array $labels): ?string
    {
        $count = count($labels);

        if ($count > 4) {
            return null;
        }

        $values = [];

        foreach ($labels as $label) {
            $value = self::number($label);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        $last = array_pop($values);

        foreach ($values as $value) {
            if ($value > 255) {
                return null;
            }
        }

        if ($last > (256 ** (5 - $count)) - 1) {
            return null;
        }

        $total = $last;

        foreach ($values as $index => $value) {
            $total += $value * (256 ** (3 - $index));
        }

        return implode('.', [($total >> 24) & 0xFF, ($total >> 16) & 0xFF, ($total >> 8) & 0xFF, $total & 0xFF]);
    }

    /**
     * One label as hex (`0x…`, `0x` alone is 0), octal (`0…`) or decimal,
     * digit by digit so nothing overflows; null when invalid or above 2^32-1.
     */
    protected static function number(string $label): ?int
    {
        if (preg_match('/\A0x([0-9a-f]*)\z/', $label, $match) === 1) {
            [$digits, $base] = [$match[1], 16];
        } elseif (preg_match('/\A0([0-7]+)\z/', $label, $match) === 1) {
            [$digits, $base] = [$match[1], 8];
        } elseif (preg_match('/\A(0|[1-9]\d*)\z/', $label) === 1) {
            [$digits, $base] = [$label, 10];
        } else {
            return null;
        }

        $value = 0;

        foreach (str_split($digits) as $digit) {
            $value = $value * $base + (int) hexdec($digit);

            if ($value > 0xFFFFFFFF) {
                return null;
            }
        }

        return $value;
    }
}
