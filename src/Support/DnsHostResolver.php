<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

class DnsHostResolver implements HostResolver
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false || $records === []) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
