<?php

declare(strict_types=1);

namespace App\Controller;

use DI\Container;
use App\Agent\Brain;
use Monolog\Logger;
use NeuronAI\Chat\Messages\AssistantMessage;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Views\Twig;

final readonly class HomeController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly SessionInterface $session
    ) {
    }

    public function index(Request $request, Response $response): Response
    {

        $time = new \DateTime()->format('H:i');
        $message = "Bonjour et bienvenue ! Comment puis-je t'aider aujourd'hui ?";

        $this->session->set('chatId', uniqid('USER_ ', true));

        return $this->twig->render($response, 'index.twig', ['time' => $time, 'message' => $message ]);
    }
}
