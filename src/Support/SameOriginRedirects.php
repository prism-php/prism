<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

use Prism\Prism\Exceptions\PrismUrlRefused;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * HTTP client middleware: a redirect is followed only within the request's
 * origin.
 *
 * Provider requests carry credentials in headers, and the HTTP client re-sends
 * every header except `Authorization` and `Cookie` when it follows a redirect
 * to another host. Rather than track which header carries a credential for
 * which provider, a redirect whose target differs from the request in scheme,
 * host or effective port is not followed at all: the response is refused
 * before the client's redirect handling sees it, so no request is made to the
 * target. Redirects within the origin are followed as before.
 *
 * Laravel pushes request middleware inside Guzzle's redirect middleware, so
 * this sees every hop, and it applies whatever `allow_redirects` options a
 * caller passes.
 */
class SameOriginRedirects
{
    public static function middleware(): callable
    {
        return static fn (callable $handler): callable => static fn (RequestInterface $request, array $options) => $handler($request, $options)->then(
            static function (ResponseInterface $response) use ($request, $options): ResponseInterface {
                // The same conditions under which Guzzle follows a redirect.
                if (empty($options['allow_redirects'])
                    || ! str_starts_with((string) $response->getStatusCode(), '3')
                    || ! $response->hasHeader('Location')) {
                    return $response;
                }

                $from = self::origin($request->getUri());
                $to = self::targetOrigin($from, $response->getHeaderLine('Location'));

                if ($to === $from) {
                    return $response;
                }

                $response->getBody()->close();

                throw new PrismUrlRefused('redirect_refused', sprintf(
                    'The provider at %s answered with a %d redirect to %s. Redirects are followed only within the same origin (scheme, host and port), so it was not followed.',
                    self::describe($from),
                    $response->getStatusCode(),
                    $to === null ? 'a location that could not be parsed' : self::describe($to),
                ));
            }
        );
    }

    /**
     * The origin a Location resolves to against the request's origin, the way
     * RFC 3986 reference resolution (and Guzzle's UriResolver) does it: a
     * reference with a scheme is absolute, one with an authority keeps only
     * the scheme, and anything else stays on the request's origin.
     *
     * @param  array{string, string, int|null}  $from
     * @return array{string, string, int|null}|null
     */
    protected static function targetOrigin(array $from, string $location): ?array
    {
        $parts = self::parse($location);

        if ($parts === null) {
            return null;
        }

        if (isset($parts['scheme'])) {
            return self::normalise($parts['scheme'], $parts['host'] ?? '', $parts['port'] ?? null);
        }

        if (isset($parts['host']) || isset($parts['port']) || isset($parts['user'])) {
            return self::normalise($from[0], $parts['host'] ?? '', $parts['port'] ?? null);
        }

        return $from;
    }

    /**
     * parse_url() as Guzzle's Uri applies it, so both read a Location the
     * same way: an IPv6 authority is set aside and other runs are encoded
     * before parsing, then decoded.
     *
     * @return array{scheme: string|null, host: string|null, port: int|null, user: string|null}|null
     */
    protected static function parse(string $location): ?array
    {
        $prefix = '';

        if (preg_match('%^(.*://\[[0-9:a-fA-F]+\])(.*?)$%', $location, $matches) === 1) {
            $prefix = $matches[1];
            $location = $matches[2];
        }

        $encoded = preg_replace_callback('%[^:/@?&=#]+%usD', static fn (array $match): string => urlencode($match[0]), $location);

        if ($encoded === null) {
            return null;
        }

        $parts = parse_url($prefix.$encoded);

        if ($parts === false) {
            return null;
        }

        return [
            'scheme' => isset($parts['scheme']) ? urldecode($parts['scheme']) : null,
            'host' => isset($parts['host']) ? urldecode($parts['host']) : null,
            'port' => $parts['port'] ?? null,
            'user' => isset($parts['user']) ? urldecode($parts['user']) : null,
        ];
    }

    /** @return array{string, string, int|null} */
    protected static function origin(UriInterface $uri): array
    {
        return self::normalise($uri->getScheme(), $uri->getHost(), $uri->getPort());
    }

    /** @return array{string, string, int|null} */
    protected static function normalise(string $scheme, string $host, ?int $port): array
    {
        $scheme = strtolower($scheme);

        return [
            $scheme,
            strtolower($host),
            $port ?? match ($scheme) {
                'http' => 80,
                'https' => 443,
                default => null,
            },
        ];
    }

    /**
     * Scheme, host and port only: never a path, query or user info. The
     * target comes from a response header, so anything outside printable
     * ASCII is replaced before it reaches a message or a log line.
     *
     * @param  array{string, string, int|null}  $origin
     */
    protected static function describe(array $origin): string
    {
        [$scheme, $host, $port] = $origin;

        $text = sprintf('%s://%s%s', $scheme, $host, $port === null ? '' : ':'.$port);

        return (string) preg_replace('/[^\x21-\x7E]/', '?', $text);
    }
}
