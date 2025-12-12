<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
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
        private BrainRegistry $brainRegistry,
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

        // Déterminer l'avatar/assistant courant (session, sinon préférence utilisateur, sinon défaut)
        $currentBrain = (string) ($this->session->get('brain_avatar') ?? '');
        if ($currentBrain === '') {
            $user = $this->entityManager->getRepository(\App\Entity\User::class)->find($this->session->get('userId'));
            if ($user !== null) {
                $params = $user->getParams() ?? [];
                $currentBrain = (string) ($params['brain_avatar'] ?? '');
            }

            if ($currentBrain === '') {
                $currentBrain = 'claire';
            }

            $this->session->set('brain_avatar', $currentBrain);
        }

        // Métadonnées du brain courant via la registry
        try {
            $meta = $this->brainRegistry->getMeta($currentBrain);
        } catch (\InvalidArgumentException) {
            // Fallback sur "claire" si le slug n'est pas valide
            $currentBrain = 'claire';
            $this->session->set('brain_avatar', $currentBrain);
            $meta = $this->brainRegistry->getMeta($currentBrain);
        }

        // Liste complète pour l'UI
        $brains = $this->brainRegistry->list();

        return $this->twig->render($response, 'chat.twig', [
            'time' => $time,
            'message' => $defaultMessage,
            'messages' => $messages,
            'uinfo' => $this->session->get('uinfo'),
            'chat_mode' => $mode,
            'layout_mode' => $layoutMode,
            'brain_info' => $meta,
            'current_brain' => $currentBrain,
            'brains' => $brains,
        ]);
    }
}
