<?php

declare(strict_types=1);

namespace App\Renderer;

use Psr\Log\LoggerInterface as Logger;
use Slim\Interfaces\ErrorRendererInterface;
use Throwable;

final readonly class HtmlErrorRenderer implements ErrorRendererInterface
{
    public function __construct(
        private VueShell $vueShell,
        private Logger $logger,
    ) {
    }

    public function __invoke(
        Throwable $exception,
        bool $displayErrorDetails
    ): string {
        if ($exception->getCode() === 404) {
            return $this->vueShell->document([
                'page' => 'error', 'baseUrl' => '',
                'code' => 404,
                'title' => 'Oups ! La page que vous recherchez est introuvable.',
                'details' => null,
            ]);
        }

        $title = is_a($exception, '\Slim\Exception\HttpException')
            ? $exception->getTitle() : 'Une erreur est survenue.';

        $details = [
            'type' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ];

        $this->logger->error('[' . $exception->getCode() . '] ' . $exception->getMessage(), ['exception' => $exception]);

        return $this->vueShell->document([
            'page' => 'error', 'baseUrl' => '',
            'code' => $exception instanceof \Slim\Exception\HttpException ? $exception->getCode() : 500,
            'title' => $title,
            'details' => $displayErrorDetails ? $details : null,
        ]);
    }
}
