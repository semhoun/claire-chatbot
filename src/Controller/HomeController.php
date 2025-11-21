<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Brain;
use Doctrine\ORM\EntityManager;
use Monolog\Logger;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Messages\AssistantMessage;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Views\Twig;

final class HomeController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionInterface $session
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $time = (new \DateTime())->format('H:i');
        $message = "Bonjour et bienvenue ! Comment puis-je t'aider aujourd'hui ?";
        $chatHistory[] = [
            'role' => 'assistant',
            'content' => $message,
            'time' => $time,
        ];
        $this->session->set('chatHistory', $chatHistory);

        return $this->view->render($response, 'index.twig', ['time' => $time, 'message' => $message ]);
    }

    public function error(Request $request, Response $response): Response
    {
        $this->logger->info('Error log');

        throw new HttpInternalServerErrorException($request, 'Try error handler');
    }
}
