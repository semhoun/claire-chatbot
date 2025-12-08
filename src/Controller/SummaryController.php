<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\Summary;
use App\Entity\ChatHistory as ChatHistoryEntity;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\Chat\Messages\UserMessage;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class SummaryController
{
    public function __construct(
        private SessionInterface $session,
        private Summary $summary,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Génère un titre et un résumé pour la conversation courante.
     * Retourne du JSON: {"title": string, "summary": string}
     */
    public function generate(Request $request, Response $response): Response
    {
        $userId = (string) $this->session->get('userId');
        $threadId = (string) $this->session->get('chatId');

        if ($userId === '' || $threadId === '') {
            return $response->withStatus(403);
        }

        // Vérifier que l'historique appartient bien à l'utilisateur
        $entityRepository = $this->entityManager->getRepository(ChatHistoryEntity::class);
        /** @var ChatHistoryEntity|null $history */
        $history = $entityRepository->createQueryBuilder('h')
            ->leftJoin('h.user', 'u')
            ->where('u.id = :userId AND h.threadId = :threadId')
            ->setParameter('userId', $userId)
            ->setParameter('threadId', $threadId)
            ->getQuery()
            ->getOneOrNullResult();

        if ($history === null) {
            return $response->withStatus(404);
        }

        // Demande à l'agent de produire le JSON (il lira l'historique depuis le ChatHistory lié à la session)
        $userMessage = new UserMessage(
            "Analyse toute la conversation en ne prenant pas en compte ce message et réponds strictement en JSON avec les clés 'title' et 'summary'."
        );

        $message = $this->summary->chat($userMessage);
        $content = trim($message->getContent());

        $title = null;
        $summary = null;
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $title = (string) ($decoded['title'] ?? '') ?: null;
            $summary = (string) ($decoded['summary'] ?? '') ?: null;
        } catch (\JsonException) {
            // Fallback minimal: ne rien enregistrer si le format n'est pas JSON
        }

        if ($title !== null || $summary !== null) {
            if ($title !== null) {
                $history->setTitle($title);
            }

            if ($summary !== null) {
                $history->setSummary($summary);
            }

            $this->entityManager->persist($history);
            $this->entityManager->flush();
        }

        $payload = json_encode([
            'title' => $title,
            'summary' => $summary,
        ], JSON_UNESCAPED_UNICODE);

        $response->getBody()->write($payload ?: '{}');
        return $response->withHeader('content-type', 'application/json');
    }
}
