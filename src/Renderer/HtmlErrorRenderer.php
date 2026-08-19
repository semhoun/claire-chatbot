<?php

declare(strict_types=1);

namespace App\Renderer;

use Psr\Log\LoggerInterface as Logger;
use Slim\Views\Twig;
use Slim\Interfaces\ErrorRendererInterface;
use Throwable;

final readonly class HtmlErrorRenderer implements ErrorRendererInterface
{
    public function __construct(
        private Twig $twig,
        private Logger $logger,
    ) {
    }

    public function __invoke(
        Throwable $exception,
        bool $displayErrorDetails
    ): string {
        if ($exception->getCode() === 404) {
            return $this->twig->fetch('error.twig', [
                'base_url' => '',
                'code' => 404,
                'title' => 'Oups ! La page que vous recherchez est introuvable.',
                'details' => null,
            ]);
        }

        $title = is_a($exception, '\Slim\Exception\HttpException')
            ? $exception->getTitle() : '500 - ' . $exception::class;

        $details = [
            'type' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ];

        $this->logger->error('[' . $exception->getCode() . '] ' . $exception->getMessage(), ['exception' => $exception]);

        return $this->twig->fetch('error.twig', [
            'base_url' => '',
            'code' => $exception->getCode(),
            'title' => $title,
            'details' => $displayErrorDetails ? $details : null,
        ]);
    }
}
