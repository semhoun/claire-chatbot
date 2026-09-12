<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use RuntimeException;

final readonly class RagUrlFetcher
{
    public function __construct(private RagUrlTransport $ragUrlTransport)
    {
    }

    public function fetch(string $url): string
    {
        $deadline = microtime(true) + 15;
        for ($redirects = 0; $redirects <= 3; ++$redirects) {
            if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) || strlen($url) > 8192) {
                throw new RuntimeException('Invalid RAG URL.');
            }

            $parts = parse_url($url);
            if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass']) || ! isset($parts['host'])) {
                throw new RuntimeException('Only HTTP(S) URLs without credentials are allowed.');
            }

            $host = trim($parts['host'], '[]');
            $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
            if ($port < 1 || $port > 65535) {
                throw new RuntimeException('Invalid RAG port.');
            }

            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $addresses = [$host];
            } else {
                // Reject ambiguous numeric hosts and local single-label names.
                if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host)) {
                    throw new RuntimeException('Invalid RAG hostname.');
                }

                $addresses = $this->ragUrlTransport->resolve($host);
            }

            if ($addresses === []) {
                throw new RuntimeException('RAG hostname has no addresses.');
            }

            foreach ($addresses as $address) {
                if (! $this->isPublicAddress($address)) {
                    throw new RuntimeException('Non-public RAG destination refused.');
                }
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('RAG fetch timed out.');
            }

            $result = $this->ragUrlTransport->get($url, $host, $port, $addresses[0], $remaining);
            if (in_array($result['status'], [301, 302, 303, 307, 308], true)) {
                // URI normalization can erase empty userinfo; reject it before resolving.
                $redirectParts = parse_url($result['location']);
                if ($redirects === 3 || $result['location'] === ''
                    || $redirectParts === false || isset($redirectParts['user']) || isset($redirectParts['pass'])
                    || preg_match('/[\x00-\x20\x7f\\\\]/', $result['location'])) {
                    throw new RuntimeException('Invalid or excessive RAG redirects.');
                }

                $url = (string) UriResolver::resolve(new Uri($url), new Uri($result['location']));
                continue;
            }

            if ($result['status'] < 200 || $result['status'] >= 300) {
                throw new RuntimeException('RAG server returned an unsuccessful response.');
            }

            return trim($result['body']);
        }

        throw new RuntimeException('RAG fetch failed.');
    }

    private function isPublicAddress(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            return false;
        }

        $packed = inet_pton($address);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return ord($packed[0]) < 224;
        }

        // Only global unicast; exclude transition, special-use and documentation ranges.
        if ((ord($packed[0]) & 0xe0) !== 0x20) {
            return false;
        }

        foreach (['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'] as $range) {
            [$network, $bits] = explode('/', $range);
            $network = inet_pton($network);
            $bytes = intdiv((int) $bits, 8);
            $remainder = (int) $bits % 8;
            if (substr($packed, 0, $bytes) === substr($network, 0, $bytes)
                && ($remainder === 0
                    || (ord($packed[$bytes]) >> 8 - $remainder) === (ord($network[$bytes]) >> 8 - $remainder))) {
                return false;
            }
        }

        return true;
    }
}
