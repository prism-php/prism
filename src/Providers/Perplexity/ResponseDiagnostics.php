<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Perplexity;

/** Provider-owned diagnostics. Never append partial output to an exception. */
class ResponseDiagnostics
{
    public const MAX_BYTES = 8192;

    public static function bounded(string $text, int $limit = self::MAX_BYTES): string
    {
        return strlen($text) <= $limit ? $text : mb_strcut($text, 0, $limit, 'UTF-8').sprintf(' [truncated at %d bytes]', $limit);
    }

    public static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * The published Response id is a run id, not a transport request id.
     * Retain additive request-id metadata if a provider/proxy supplies it;
     * its absence is explicit and never filled with a fabricated id.
     *
     * @param  array<string, mixed>  $data
     */
    public static function requestId(array $data, ?string $header): ?string
    {
        return self::text($header) ?? self::text($data['request_id'] ?? null) ?? self::text($data['provider_request_id'] ?? null);
    }

    /** @param array<string, mixed> $data */
    public static function message(array $data, ?string $requestId): string
    {
        $message = self::text(data_get($data, 'error.message'));
        $reason = self::text(data_get($data, 'incomplete_details.reason'));
        $type = self::text(data_get($data, 'error.type'));
        $code = data_get($data, 'error.code');
        $parts = [];
        if ($type !== null) {
            $parts[] = self::bounded($type, 256);
        }
        if (is_string($code) || is_int($code)) {
            $parts[] = 'code='.self::bounded((string) $code, 256);
        }
        $parts[] = self::bounded($message ?? ($reason === null ? 'provider returned no error message' : 'incomplete reason: '.$reason));
        if ($reason !== null && $message !== null) {
            $parts[] = 'incomplete reason: '.self::bounded($reason, 256);
        }
        $parts[] = 'request_id='.($requestId === null ? 'not provided by provider' : self::bounded($requestId, 256));
        $id = self::text($data['id'] ?? null);
        if ($id !== null) {
            $parts[] = 'response_id='.self::bounded($id, 256);
        }

        return implode('; ', $parts);
    }
}
