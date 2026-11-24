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
 * private address is not caught, so this checks what the url says; it does
 * not protect against a caller who chooses a hostname. What is caught is an
 * address literal in the spellings an OS resolver reads (decimal, octal, hex
 * and shortened IPv4, IPv6 including IPv4-mapped, NAT64 and 6to4), and the
 * documented loopback and metadata names. The address ranges are
 * PublicUrl's, so the two guards cannot drift apart.
 *
 * The host is judged AS WRITTEN and never decoded. Some HTTP clients
 * percent-decode a host before they connect, so a check that read the text
 * and a client that decoded it would judge different hosts. A host holding
 * anything but letters, digits, `-` and `.` (and, inside brackets, an IPv6
 * literal's `:`) is refused, `%` included, and so is a backslash anywhere in
 * the authority, which URL parsers split in different places. Trailing dots
 * are removed before the address step, because a resolver reads `localhost.`
 * as `localhost`.
 *
 * Spelled identically, rule for rule, in prism-ts (`assertPerCallUrl`) and
 * prism-py (`assert_per_call_url`). No message names the url or the address.
 */
class PerCallUrl
{
    public const PRIVATE_MESSAGE = 'A per-call provider url may not point at a loopback, private, link-local or metadata address. Allow it in the provider config with allow_private_per_call_url.';

    public const SCHEME_MESSAGE = 'A per-call provider url must be an absolute http or https url.';

    public const HOST_MESSAGE = 'A per-call provider url host may hold only letters, digits, hyphens and dots, or an IPv6 address in brackets.';

    public const IDN_MESSAGE = 'A per-call provider url with a non-ASCII host can only be checked with the intl extension installed.';

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
     * @throws PrismUrlRefused `scheme_not_allowed`, `private_address_refused`
     *                         or `idn_unavailable`
     */
    public static function assert(string $url): void
    {
        if (preg_match('#\Ahttps?://#i', $url) !== 1) {
            throw new PrismUrlRefused('scheme_not_allowed', self::SCHEME_MESSAGE);
        }

        $written = self::writtenHost($url);

        if ($written === '') {
            throw new PrismUrlRefused('scheme_not_allowed', self::SCHEME_MESSAGE);
        }

        if ($written === null || preg_match('/\A(?:[A-Za-z0-9.\-\x80-\xFF]+|\[[A-Za-z0-9.:\-]+\])\z/', $written) !== 1) {
            throw self::hostRefused();
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
            if (! static::intlAvailable()) {
                throw new PrismUrlRefused('idn_unavailable', self::IDN_MESSAGE);
            }

            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (! is_string($ascii)) {
                throw self::refused();
            }

            $host = $ascii;
        }

        $host = rtrim(strtolower($host), '.');

        if ($host === '') {
            throw new PrismUrlRefused('scheme_not_allowed', self::SCHEME_MESSAGE);
        }

        // Again after IDNA, which can map a non-ASCII character to `%` or `/`.
        if (preg_match('/\A[a-z0-9.:\-]+\z/', $host) !== 1) {
            throw self::hostRefused();
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
     * The private-address code: a host that cannot be read safely is refused
     * under the rule it might be evading.
     */
    protected static function hostRefused(): PrismUrlRefused
    {
        return new PrismUrlRefused('private_address_refused', self::HOST_MESSAGE);
    }

    protected static function intlAvailable(): bool
    {
        return function_exists('idn_to_ascii');
    }

    /**
     * The host exactly as written. The authority runs from after `//` to the
     * first `/`, `?` or `#`; the host follows its last `@` and ends at the
     * port's `:` (or, when bracketed, at the first `]`). Null when the
     * authority holds a backslash. Nothing is decoded.
     */
    protected static function writtenHost(string $url): ?string
    {
        $rest = substr($url, (int) strpos($url, '//') + 2);
        $authority = substr($rest, 0, strcspn($rest, '/?#'));

        if (str_contains($authority, chr(92))) {
            return null;
        }

        $at = strrpos($authority, '@');
        $hostPort = $at === false ? $authority : substr($authority, $at + 1);

        if (str_starts_with($hostPort, '[')) {
            $end = strpos($hostPort, ']');

            return $end === false ? $hostPort : substr($hostPort, 0, $end + 1);
        }

        $colon = strpos($hostPort, ':');

        return $colon === false ? $hostPort : substr($hostPort, 0, $colon);
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
