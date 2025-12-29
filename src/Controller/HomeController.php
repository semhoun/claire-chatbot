<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Entity\ChatHistory as ChatHistoryEntity;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class HomeController
{
    public function __construct(
        private Twig $twig,
        private SessionInterface $session,
        private BrainRegistry $brainRegistry,
        private UserChatHistory $userChatHistory,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $time = new \DateTime()->format('H:i');

        // Conserver le chatId courant s'il existe, sinon en générer un nouveau
        $chatId = $this->session->get('chatId');
        if (! $chatId) {
            $chatId = uniqid('', true);
            $this->session->set('chatId', $chatId);
        }

        $currentBrain = $this->session->get('brain_avatar');

        // Charger les messages existants pour le thread courant si disponibles
        $messages = $this->userChatHistory->getMessages();
        if ($messages === []) {
            $messages[] = new AssistantMessage(
                $this->brainRegistry->get($currentBrain)->getOpeningText()
            );
        }

        // Default chat mode
        $mode = $this->session->get('chat_mode') ?? 'chat';
        // Default layout width mode
        $layoutMode = $this->session->get('layout_mode') ?? 'full';

        // Métadonnées du brain courant via la registry
        try {
            $meta = $this->brainRegistry->getMeta($currentBrain);
        } catch (\InvalidArgumentException) {
            // Fallback sur "athena" si le slug n'est pas valide
            $currentBrain = 'athena';
            $this->session->set('brain_avatar', $currentBrain);
            $meta = $this->brainRegistry->getMeta($currentBrain);
        }

        return $this->twig->render($response, 'chat.twig', [
            'time' => $time,
            'messages' => $messages,
            'uinfo' => $this->session->get('uinfo'),
            'chat_mode' => $mode,
            'layout_mode' => $layoutMode,
            'brain_info' => $meta,
            'current_brain' => $currentBrain,
            'brains' => $this->brainRegistry->list(),
        ]);
    }
}
