<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class RagUrlTransport
{
    public const int MAX_BYTES = 2 * 1024 * 1024;

    /** @return list<string> */
    public function resolve(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            throw new RuntimeException('RAG DNS lookup failed.');
        }

        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip']) || isset($record['ipv6'])) {
                $addresses[] = $record['ip'] ?? $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    /** @return array{status:int,location:string,body:string} */
    public function get(string $url, string $host, int $port, string $ip, float $timeout): array
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('RAG URL fetching requires ext-curl.');
        }

        $body = '';
        $location = '';
        $headerBytes = 0;
        $pinnedIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            // Environment proxies must not bypass the validated destination IP.
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_RESOLVE => filter_var($host, FILTER_VALIDATE_IP)
                ? []
                : [sprintf('%s:%d:%s', $host, $port, $pinnedIp)],
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, max(1, (int) ($timeout * 1000))),
            CURLOPT_TIMEOUT_MS => max(1, (int) ($timeout * 1000)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'ClaireBot/1.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    return 0;
                }

                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location, &$headerBytes): int {
                $headerBytes += strlen($line);
                if ($headerBytes > 32768) {
                    return 0;
                }

                if (str_starts_with(strtolower($line), 'location:')) {
                    $location = trim(substr($line, 9));
                }

                if (str_starts_with(strtolower($line), 'content-length:')
                    && (float) trim(substr($line, 15)) > self::MAX_BYTES) {
                    return 0;
                }

                return strlen($line);
            },
        ];
        $status = $this->execute($options);
        return ['status' => $status, 'location' => $location, 'body' => $body];
    }

    /** @param array<int, mixed> $options */
    protected function execute(array $options): int
    {
        $handle = curl_init();
        try {
            curl_setopt_array($handle, $options);
            if (curl_exec($handle) === false) {
                throw new RuntimeException('RAG transfer failed or exceeded its limits.');
            }

            return curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            unset($handle);
        }
    }
}
