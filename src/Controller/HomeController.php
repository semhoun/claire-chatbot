<?php

declare(strict_types=1);

namespace App\Controller;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class HomeController
{
    public function __construct(
        private Twig $twig,
        private SessionInterface $session
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $time = new \DateTime()->format('H:i');
        $message = "Bonjour et bienvenue ! Comment puis-je t'aider aujourd'hui ?";

        $this->session->set('chatId', uniqid('', true));

        // Default chat mode
        $mode = $this->session->get('chat_mode') ?? 'chat';
        $this->session->set('chat_mode', $mode);

        return $this->twig->render($response, 'chat.twig', [
            'time' => $time,
            'message' => $message,
            'uinfo' => $this->session->get('uinfo'),
            'chat_mode' => $mode,
        ]);
    }
}
