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
 * and shortened IPv4, IPv6 including IPv4-mapped, SIIT-translated, NAT64,
 * 6to4 and ISATAP), the hosts-file loopback names and the documented
 * metadata names. Format and invisible characters are refused before IDNA. The address ranges are
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
     * Names that reach a non-public address without DNS: RFC 6761's
     * `localhost`, the names the stock hosts files map to loopback or to an
     * IPv6 local or multicast address, and the documented cloud metadata
     * names. Hosts files: Debian, Ubuntu and Docker (`ip6-*`); RHEL, CentOS,
     * Fedora and Amazon Linux (`localhost4`, `localhost6` and their
     * `.localdomainN` forms); openSUSE (`ipv6-*`); Alpine
     * (`localhost.localdomain`); macOS (`broadcasthost`). Windows ships no
     * active entries. `*.localhost` and `*.localhost.localdomain` (which
     * nss-myhostname answers) are matched as suffixes.
     */
    protected const NAMES = [
        'localhost',
        'localhost.localdomain',
        'localhost4',
        'localhost4.localdomain4',
        'localhost6',
        'localhost6.localdomain6',
        'ip6-localhost',
        'ip6-loopback',
        'ip6-localnet',
        'ip6-mcastprefix',
        'ip6-allnodes',
        'ip6-allrouters',
        'ipv6-localhost',
        'ipv6-loopback',
        'ipv6-localnet',
        'ipv6-mcastprefix',
        'ipv6-allnodes',
        'ipv6-allrouters',
        'ipv6-allhosts',
        'broadcasthost',
        'metadata',
        'metadata.google.internal',
        'instance-data',
    ];

    /** Every name under these reaches loopback without DNS. */
    protected const SUFFIXES = ['.localhost', '.localhost.localdomain'];

    /**
     * Refused anywhere in the authority, before IDNA: every format or
     * invisible character (Unicode's default-ignorable code points, the Cf
     * format controls, the C1 controls and the non-ASCII spaces) and the four
     * UTS46 deviation characters (U+00DF, U+03C2, U+200C, U+200D). IDNA
     * implementations disagree on these: an HTTP client that deletes a
     * zero-width joiner contacts `localhost` where a nontransitional
     * conversion reads a public name. Spelled code point for code point in
     * prism-ts (`INVISIBLE`) and prism-py (`_INVISIBLE`). No other check is
     * relied on.
     */
    protected const INVISIBLE = '/[\x{0080}-\x{00A0}\x{00AD}\x{00DF}\x{034F}\x{03C2}\x{0600}-\x{0605}\x{061C}\x{06DD}\x{070F}\x{0890}-\x{0891}\x{08E2}\x{115F}-\x{1160}\x{1680}\x{17B4}-\x{17B5}\x{1806}\x{180B}-\x{180F}\x{2000}-\x{200F}\x{2028}-\x{202F}\x{205F}-\x{206F}\x{2800}\x{3000}\x{3164}\x{FE00}-\x{FE0F}\x{FEFF}\x{FFA0}\x{FFF0}-\x{FFFB}\x{110BD}\x{110CD}\x{13430}-\x{1343F}\x{1BCA0}-\x{1BCA3}\x{1D173}-\x{1D17A}\x{E0000}-\x{E0FFF}]/u';

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

        // Text that is not valid UTF-8 (false) is refused as well.
        if (preg_match(self::INVISIBLE, self::authority($url)) !== 0) {
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

            // Nontransitional, with the joiner (CONTEXTJ) and bidi rules, so the
            // result does not depend on the ICU version. Any error refuses.
            $ascii = idn_to_ascii(
                $host,
                IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_CONTEXTJ | IDNA_CHECK_BIDI,
                INTL_IDNA_VARIANT_UTS46,
                $info,
            );

            if (! is_string($ascii) || ($info['errors'] ?? 1) !== 0) {
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

        if (in_array($host, self::NAMES, true)) {
            throw self::refused();
        }

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw self::refused();
            }
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
     * The authority as written: from after `//` to the first `/`, `?` or `#`.
     */
    protected static function authority(string $url): string
    {
        $rest = substr($url, (int) strpos($url, '//') + 2);

        return substr($rest, 0, strcspn($rest, '/?#'));
    }

    /**
     * The host exactly as written. The host follows the authority's last `@`
     * and ends at the port's `:` (or, when bracketed, at the first `]`). Null
     * when the authority holds a backslash. Nothing is decoded.
     */
    protected static function writtenHost(string $url): ?string
    {
        $authority = self::authority($url);

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
