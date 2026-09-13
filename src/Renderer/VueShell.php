<?php

declare(strict_types=1);

namespace App\Renderer;

use App\Services\Settings;
use Psr\Http\Message\ResponseInterface;

final readonly class VueShell
{
    public function __construct(private ?string $appRoot = null)
    {
    }

    /** @param array<string, mixed> $data */
    public function document(array $data = []): string
    {
        $appRoot = $this->appRoot ?? Settings::getAppRoot();
        $shell = file_get_contents($appRoot . '/frontend/shell.html');
        if ($shell === false) {
            throw new \RuntimeException('Vue shell unavailable');
        }

        return strtr($shell, [
            '__BASE_URL__' => htmlspecialchars((string) ($data['baseUrl'] ?? ''), ENT_QUOTES, 'UTF-8'),
            '__APP_CSS__' => $this->stylesheets($appRoot, (string) ($data['baseUrl'] ?? '')),
            '__PAGE_DATA__' => json_encode($data, JSON_THROW_ON_ERROR | JSON_HEX_TAG
                | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ]);
    }

    private function stylesheets(string $appRoot, string $baseUrl): string
    {
        $path = $appRoot . '/public/build/.vite/manifest.json';
        // Allow server-side tests and shell rendering before the frontend is built.
        if (! is_file($path)) {
            return '';
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Vue asset manifest unavailable');
        }

        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $entry = is_array($manifest) ? ($manifest['frontend/main.ts'] ?? null) : null;
        if (! is_array($entry) || ! isset($entry['css']) || ! is_array($entry['css'])) {
            throw new \RuntimeException('Vue asset manifest missing entry CSS');
        }

        $links = [];
        foreach ($entry['css'] as $file) {
            if (! is_string($file) || $file === '') {
                throw new \RuntimeException('Vue asset manifest contains invalid CSS');
            }

            $url = htmlspecialchars(rtrim($baseUrl, '/') . '/build/' . $file, ENT_QUOTES, 'UTF-8');
            $links[] = '<link rel="stylesheet" href="' . $url . '">';
        }

        return implode("\n", $links);
    }

    /** @param array<string, mixed> $data */
    public function respond(ResponseInterface $response, array $data = []): ResponseInterface
    {
        $response->getBody()->write($this->document($data));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
