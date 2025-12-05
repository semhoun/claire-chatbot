<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ChatHistory as ChatHistoryEntity;
use Doctrine\ORM\EntityManagerInterface;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class HomeController
{
    public function __construct(
        private Twig $twig,
        private SessionInterface $session,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $time = new \DateTime()->format('H:i');
        $defaultMessage = "Bonjour et bienvenue ! Comment puis-je t'aider aujourd'hui ?";

        // Conserver le chatId courant s'il existe, sinon en générer un nouveau
        $chatId = $this->session->get('chatId');
        if (! $chatId) {
            $chatId = uniqid('', true);
            $this->session->set('chatId', $chatId);
        }

        // Charger les messages existants pour le thread courant si disponibles
        $userId = (string) ($this->session->get('userId') ?? '');
        $messages = $this->entityManager->getRepository(ChatHistoryEntity::class)->getShareGptMessages($userId, $chatId);
        if ($messages === null) {
            $messages = [];
        }

        // Default chat mode
        $mode = $this->session->get('chat_mode') ?? 'chat';
        // Default layout width mode
        $layoutMode = $this->session->get('layout_mode') ?? 'full';

        return $this->twig->render($response, 'chat.twig', [
            'time' => $time,
            'message' => $defaultMessage,
            'messages' => $messages,
            'uinfo' => $this->session->get('uinfo'),
            'chat_mode' => $mode,
            'layout_mode' => $layoutMode,
        ]);
    }
}
