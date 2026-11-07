<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;

trait DownloadsOutputs
{
    protected const MAX_DOWNLOAD_BYTES = 25 * 1024 * 1024;

    /**
     * Download provider-returned media without the prediction client's token,
     * headers, cookies or options. Redirects are refused rather than allowing a
     * checked host to choose another destination. Read at most the cap + 1 byte.
     */
    protected function downloadOutput(string $url): string
    {
        if (! $this->isDownloadableUrl($url)) {
            throw new PrismException('Replicate output URL refused by the HTTPS download_hosts policy.');
        }

        try {
            $response = $this->outputClient()
                ->withOptions(['stream' => true])
                ->get($url);
            $stream = $response->toPsrResponse()->getBody();

            try {
                if (! $response->successful()) {
                    throw new PrismException('Replicate output download failed.');
                }

                $declared = $response->header('Content-Length');

                if (preg_match('/^\d+$/', (string) $declared) === 1 && (int) $declared > self::MAX_DOWNLOAD_BYTES) {
                    throw new PrismException('Replicate output exceeds the 25 MiB download limit.');
                }

                $content = '';

                do {
                    $chunk = $stream->read(min(65536, self::MAX_DOWNLOAD_BYTES - strlen($content) + 1));

                    if ($chunk === '' && ! $stream->eof()) {
                        throw new PrismException('Replicate output download stalled.');
                    }

                    $content .= $chunk;

                    if (strlen($content) > self::MAX_DOWNLOAD_BYTES) {
                        throw new PrismException('Replicate output exceeds the 25 MiB download limit.');
                    }
                } while (! $stream->eof());

                if ($content === '') {
                    throw new PrismException('Replicate output download is empty.');
                }

                return $content;
            } finally {
                $stream->close();
            }
        } catch (PrismException $exception) {
            throw $exception;
        } catch (\Exception) {
            // Transport exceptions can contain signed URLs or response bodies.
            throw new PrismException('Replicate output download failed.');
        }
    }

    protected function outputClient(): PendingRequest
    {
        return Http::timeout(30)->connectTimeout(10)->withoutRedirecting();
    }

    protected function isDownloadableUrl(string $url): bool
    {
        $allowedHosts = config('prism.providers.replicate.download_hosts', ['replicate.delivery']);

        return is_array($allowedHosts) && $this->isAllowedOutputUrl($url, $allowedHosts);
    }

    /** @param array<array-key, mixed> $allowedHosts */
    protected function isAllowedOutputUrl(string $url, array $allowedHosts, bool $allowSubdomains = true): bool
    {
        $parts = parse_url($url);

        if ($parts === false || filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || ($parts['port'] ?? 443) !== 443) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');

        foreach ($allowedHosts as $allowedHost) {
            if (! is_string($allowedHost)) {
                continue;
            }
            if ($allowedHost === '') {
                continue;
            }
            $allowedHost = strtolower($allowedHost);

            if ($host === $allowedHost || ($allowSubdomains && str_ends_with($host, '.'.$allowedHost))) {
                return true;
            }
        }

        return false;
    }
}
