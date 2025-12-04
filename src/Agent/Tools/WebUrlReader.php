<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
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

        try {
            $client = new Client(['timeout' => 15]);
            $response = $client->request('GET', $url);
            $html = (string) $response->getBody();
            $html = $this->absolutizeHtmlUrls($html, $this->baseDocument($url));
            $htmlConverter = new HtmlConverter([
                'hard_break' => false,
                'strip_tags' => true,
                'use_autolinks' => false,
                'remove_nodes' => 'script style',
            ]);
            $htmlConverter->getEnvironment()->addConverter(new TableConverter());
            $markdown = $htmlConverter->convert($html);
            $markdown = substr($markdown, 0, (int) $this->maxContentLength);
        } catch (\Exception $exception) {
            throw new ToolException('Failed to read URL: ' . $exception->getMessage());
        }

        return $markdown;
    }

    #[\Override]
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
        if ($uri->getScheme() === '' || $uri->getHost() === '') {
            return '';
        }

        $path = $uri->getPath();
        if ($path === '' || ! str_ends_with($path, '/')) {
            $path = rtrim(dirname($path === '' ? '/' : $path), '/') . '/';
        }

        // On supprime query/fragment pour obtenir la base du document
        return (string) $uri->withPath($path)->withQuery('')->withFragment('');
    }

    private function resolveUrl(string $base, string $maybeRelative): string
    {
        // Laisse passer mailto:, data:, tel:, javascript:, #ancres et //host
        if ($maybeRelative === '' || $maybeRelative[0] === '#' ||
            preg_match('~^(data:|mailto:|tel:|javascript:)~i', $maybeRelative)) {
            return $maybeRelative;
        }

        if (str_starts_with($maybeRelative, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'http';
            return $scheme . ':' . $maybeRelative;
        }

        $uri = UriResolver::resolve(new Uri($base), new Uri($maybeRelative));
        return (string) $uri;
    }

    private function absolutizeHtmlUrls(string $html, string $baseUrl): string
    {
        // Assurer l'UTF-8
        $internalErrors = libxml_use_internal_errors(true);
        $domDocument = new \DOMDocument('1.0', 'UTF-8');
        // On force DOMDocument à interpréter la chaîne comme UTF-8 en préfixant
        // une déclaration d'encodage XML (technique recommandée pour libxml).
        $domDocument->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $domxPath = new \DOMXPath($domDocument);
        // Attributs à réécrire
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
                foreach ($attrs as $attr) {
                    if (! $node->hasAttribute($attr)) {
                        continue;
                    }

                    $val = trim((string) $node->getAttribute($attr));
                    if ($val === '') {
                        continue;
                    }

                    if ($attr === 'srcset') {
                        // srcset peut contenir plusieurs URLs séparées par des virgules
                        $parts = array_map(trim(...), explode(',', $val));
                        $newParts = [];
                        foreach ($parts as $part) {
                            // pattern: url [descriptor]
                            if ($part === '') {
                                continue;
                            }

                            $chunks = preg_split('/\s+/', $part, 2);
                            $url = $chunks[0];
                            $desc = $chunks[1] ?? '';
                            $abs = $this->resolveUrl($baseUrl, $url);
                            $newParts[] = trim($abs . ' ' . $desc);
                        }

                        $node->setAttribute($attr, implode(', ', $newParts));
                    } else {
                        $abs = $this->resolveUrl($baseUrl, $val);
                        $node->setAttribute($attr, $abs);
                    }
                }
            }
        }

        // Si une balise <base> existe, la retirer pour éviter des effets de bord ultérieurs
        foreach ($domxPath->query('//base') as $base) {
            $base->parentNode?->removeChild($base);
        }

        $out = $domDocument->saveHTML();
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);
        return $out;
    }
}
