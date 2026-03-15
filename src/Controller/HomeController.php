<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Entity\ChatHistory as ChatHistoryEntity;
use App\Services\Auth;
use App\Services\Session\SessionInterface;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class HomeController
{
    public function __construct(
        private Twig $twig,
        private BrainRegistry $brainRegistry,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        if (! $session instanceof SessionInterface) {
            return $response->withStatus(500);
        }

        $time = new \DateTime()->format('H:i');

        // Conserver le chatId courant s'il existe, sinon en générer un nouveau
        $chatId = $session->get('chatId');
        if (! $chatId) {
            $chatId = uniqid('', true);
            $session->set('chatId', $chatId);

            // Nettoyage des conversations vides de l'utilisateur
            $userId = (string) $session->get(Auth::USERID);
            if ($userId !== '') {
                $this->entityManager->getRepository(ChatHistoryEntity::class)->deleteEmptyConversations($userId);
            }
        }

        $currentBrain = $session->get('brain_avatar');

        // Charger les messages existants pour le thread courant si disponibles
        $userChatHistory = new UserChatHistory(
            session: $session,
            pdo: $this->entityManager->getConnection()->getNativeConnection(),
            table: UserChatHistory::TABLE,
            contextWindow: 50000
        );
        $messages = $userChatHistory->getMessages();
        if ($messages === []) {
            $messages[] = new AssistantMessage(
                $this->brainRegistry->get($currentBrain, $session)->getOpeningText()
            );
        }

        // Default chat mode
        $mode = $session->get('chat_mode') ?? 'chat';
        // Default layout width mode
        $layoutMode = $session->get('layout_mode') ?? 'full';

        // Métadonnées du brain courant via la registry
        try {
            $meta = $this->brainRegistry->getMeta($currentBrain);
        } catch (\InvalidArgumentException) {
            // Fallback sur "athena" si le slug n'est pas valide
            $currentBrain = 'athena';
            $session->set('brain_avatar', $currentBrain);
            $meta = $this->brainRegistry->getMeta($currentBrain);
        }

        return $this->twig->render($response, 'chat.twig', [
            'time' => $time,
            'messages' => $messages,
            'uinfo' => $session->get(Auth::USERINFO),
            'chat_mode' => $mode,
            'layout_mode' => $layoutMode,
            'brain_info' => $meta,
            'current_brain' => $currentBrain,
            'brains' => $this->brainRegistry->list(),
        ]);
    }
}
