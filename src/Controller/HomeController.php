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
        // Si non authentifié, afficher une page d'accueil avec bouton SSO
        if (! $this->session->get('logged')) {
            return $this->twig->render($response, 'welcome.twig', []);
        }

        $time = new \DateTime()->format('H:i');
        $message = "Bonjour et bienvenue ! Comment puis-je t'aider aujourd'hui ?";

        $this->session->set('chatId', uniqid('USER_ ', true));

        return $this->twig->render($response, 'chat.twig', ['time' => $time, 'message' => $message ]);
    }
}
