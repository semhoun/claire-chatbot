<?php

declare(strict_types=1);

namespace App\Renderer;

use App\Services\Settings;
use Psr\Http\Message\ResponseInterface;

final readonly class VueShell
{
    /** @param array<string, mixed> $data */
    public function document(array $data = []): string
    {
        $shell = file_get_contents(Settings::getAppRoot() . '/frontend/shell.html');
        if ($shell === false) {
            throw new \RuntimeException('Vue shell unavailable');
        }

        return strtr($shell, [
            '__BASE_URL__' => htmlspecialchars((string) ($data['baseUrl'] ?? ''), ENT_QUOTES, 'UTF-8'),
            '__PAGE_DATA__' => json_encode($data, JSON_THROW_ON_ERROR | JSON_HEX_TAG
                | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ]);
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
