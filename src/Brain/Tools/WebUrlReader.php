<?php

declare(strict_types=1);

namespace App\Brain\Tools;

use App\Services\Markdown;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class WebUrlReader extends Tool
{
    public function __construct(private readonly string $maxContentLength = '20000')
    {
        parent::__construct(
            'url_reader',
            'Get the content of a URL in markdown format.'
        );
    }

    public function __invoke(string $url): string
    {
        if (! \filter_var($url, \FILTER_VALIDATE_URL)) {
            throw new ToolException('Invalid URL.');
        }

        $this->validateUrlForSsrf($url);

        try {
            $client = new Client(['timeout' => 15]);
            $response = $client->request('GET', $url);
            $html = (string) $response->getBody();
            $html = $this->absolutizeHtmlUrls($html, $this->baseDocument($url));
            $markdown = Markdown::fromHtml($html);
            $markdown = substr($markdown, 0, (int) $this->maxContentLength);
        } catch (\Exception $exception) {
            throw new ToolException('Failed to read URL: ' . $exception->getMessage(), $exception->getCode(), $exception);
        }

        return $markdown;
    }

    #[\Override]
    /** @return array<int, ToolProperty> */
    protected function properties(): array
    {
        return [
            new ToolProperty(
                'url',
                PropertyType::STRING,
                'The URL to read.',
                true
            ),
        ];
    }

    private function baseDocument(string $url): string
    {
        $uri = new Uri($url);

        if (! $this->isValidUri($uri)) {
            return '';
        }

        $path = $this->normalizePath($uri->getPath());

        return (string) $uri->withPath($path)->withQuery('')->withFragment('');
    }

    private function isValidUri(Uri $uri): bool
    {
        return $uri->getScheme() !== '' && $uri->getHost() !== '';
    }

    private function normalizePath(string $path): string
    {
        if ($path === '' || ! str_ends_with($path, '/')) {
            return rtrim(dirname($path === '' ? '/' : $path), '/') . '/';
        }

        return $path;
    }

    private function resolveUrl(string $base, string $maybeRelative): string
    {
        if ($this->shouldPassThrough($maybeRelative)) {
            return $maybeRelative;
        }

        if (str_starts_with($maybeRelative, '//')) {
            return $this->resolveProtocolRelativeUrl($base, $maybeRelative);
        }

        return (string) UriResolver::resolve(new Uri($base), new Uri($maybeRelative));
    }

    private function shouldPassThrough(string $url): bool
    {
        return $url === '' ||
            $url[0] === '#' ||
            preg_match('~^(data:|mailto:|tel:|javascript:)~i', $url);
    }

    private function resolveProtocolRelativeUrl(string $base, string $url): string
    {
        $scheme = parse_url($base, PHP_URL_SCHEME) !== null && parse_url($base, PHP_URL_SCHEME) !== false
            ? parse_url($base, PHP_URL_SCHEME)
            : 'http';
        return $scheme . ':' . $url;
    }

    private function absolutizeHtmlUrls(string $html, string $baseUrl): string
    {
        $internalErrors = libxml_use_internal_errors(true);
        $domDocument = $this->createDomDocument($html);
        $domxPath = new \DOMXPath($domDocument);

        $this->processAttributeMap($domxPath, $baseUrl);
        $this->removeBaseTag($domxPath);

        $out = $domDocument->saveHTML();
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        return $out;
    }

    private function createDomDocument(string $html): \DOMDocument
    {
        $domDocument = new \DOMDocument('1.0', 'UTF-8');
        $domDocument->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        return $domDocument;
    }

    private function processAttributeMap(\DOMXPath $domxPath, string $baseUrl): void
    {
        $attrMap = [
            'a' => ['href'],
            'link' => ['href'],
            'img' => ['src', 'srcset'],
            'script' => ['src'],
            'form' => ['action'],
            'source' => ['src', 'srcset'],
            'video' => ['poster'],
        ];

        foreach ($attrMap as $tag => $attrs) {
            foreach ($domxPath->query('//' . $tag) as $node) {
                $this->processNodeAttributes($node, $attrs, $baseUrl);
            }
        }
    }

    /**
     * @param array<int, string> $attrs
     */
    private function processNodeAttributes(\DOMElement $domElement, array $attrs, string $baseUrl): void
    {
        foreach ($attrs as $attr) {
            if (! $domElement->hasAttribute($attr)) {
                continue;
            }

            $val = trim($domElement->getAttribute($attr));
            if ($val === '') {
                continue;
            }

            $newVal = $attr === 'srcset'
                ? $this->processSrcset($val, $baseUrl)
                : $this->resolveUrl($baseUrl, $val);

            $domElement->setAttribute($attr, $newVal);
        }
    }

    private function processSrcset(string $srcset, string $baseUrl): string
    {
        $parts = array_map(trim(...), explode(',', $srcset));
        $newParts = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $newParts[] = $this->processSrcsetPart($part, $baseUrl);
        }

        return implode(', ', $newParts);
    }

    private function processSrcsetPart(string $part, string $baseUrl): string
    {
        $chunks = preg_split('/\s+/', $part, 2);
        $url = $chunks[0];
        $desc = $chunks[1] ?? '';
        $abs = $this->resolveUrl($baseUrl, $url);

        return trim($abs . ' ' . $desc);
    }

    private function removeBaseTag(\DOMXPath $domxPath): void
    {
        foreach ($domxPath->query('//base') as $base) {
            $base->parentNode?->removeChild($base);
        }
    }

    private function validateUrlForSsrf(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false) {
            throw new ToolException('Invalid URL host.');
        }

        // 1. Check if the host itself is an IP and if it's private
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new ToolException('SSRF protection: Private or reserved IP range is not allowed.');
            }

            return;
        }

        // 2. Resolve the host to get its IP addresses
        $ips = gethostbynamel($host);
        if ($ips === false || $ips === []) {
            // If resolution fails, we'll let Guzzle try, but it will likely fail too.
            // Or we could be strict and throw an error.
            return;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new ToolException(sprintf('SSRF protection: Host "%s" resolves to a private or reserved IP "%s".', $host, $ip));
            }
        }
    }
}
