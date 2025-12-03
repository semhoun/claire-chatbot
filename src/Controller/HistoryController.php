<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ChatHistory as ChatHistoryEntity;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class HistoryController
{
    public function __construct(
        private Twig $twig,
        private SessionInterface $session,
        private EntityManagerInterface $em,
    ) {
    }

    public function count(Request $request, Response $response): Response
    {
        $userId = (string) $this->session->get('userId');
        $count = $this->em->getRepository(ChatHistoryEntity::class)->countByUserId($userId);
        $response->getBody()->write((string)$count);
        return $response;
    }

    public function list(Request $request, Response $response): Response
    {
        $userId = (string) $this->session->get('userId');
        $histories = $this->em->getRepository(ChatHistoryEntity::class)->getHistoryList($userId);
        return $this->twig->render($response, 'partials/history_list.twig', [
            'histories' => $histories,
        ]);
    }

    /**
     * Ouvre une conversation de l'historique et remplace la conversation courante.
     * - Vérifie que l'historique appartient à l'utilisateur en session
     * - Met à jour la session `chatId` avec le `thread_id` sélectionné
     * - Retourne le HTML des messages pour remplacer le conteneur #messages (HTMX)
     */
    public function open(Request $request, Response $response): Response
    {
        $userId = (string) $this->session->get('userId');
        if ($userId === '') {
            return $response->withStatus(403);
        }

        $threadId = $request->getAttribute('threadId');
        if ($threadId === null) {
            return $response->withStatus(400);
        }

        $messages = $this->em->getRepository(ChatHistoryEntity::class)->getShareGptMessages($userId, $threadId);
        if ($messages === null) {
            return $response->withStatus(400);
        }
        $this->session->set('chatId', $threadId);

        return $this->twig->render($response, 'partials/messages_list.twig', [
            'messages' => $messages,
        ]);
    }
}
