<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Entity\ChatHistory as ChatHistoryEntity;
use App\Services\Auth;
use App\Services\ComfyUIWorkflowRegistry;
use App\Services\Session\SessionFromRequestTrait;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class HomeController
{
    use SessionFromRequestTrait;

    public function __construct(
        private Twig $twig,
        private BrainRegistry $brainRegistry,
        private EntityManagerInterface $entityManager,
        private ComfyUIWorkflowRegistry $comfyUIWorkflowRegistry,
        private Settings $settings,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $time = new \DateTime()->format('H:i');

        $currentBrain = $session->get('brain_avatar');
        $layoutMode = $session->get('layout_mode');
        $comfyuiEnabled = $this->settings->get('comfyui.enabled') === true;
        $comfyuiWorkflows = $comfyuiEnabled ? $this->comfyUIWorkflowRegistry->list() : [];
        $currentComfyuiWorkflow = (string) $session->get(ComfyUIWorkflowRegistry::SESSION_KEY, '');

        // Charger les messages existants pour le thread courant si disponibles
        $userChatHistory = new UserChatHistory(
            session: $session,
            pdo: $this->entityManager->getConnection()->getNativeConnection(),
            contextWindow: $this->settings->get('llm.openai.contextWindow')
        );

        // Conserver le chatId courant s'il existe, sinon en générer un nouveau
        $chatId = $session->get('chatId');
        if ($chatId === null) {
            set_time_limit((int) $this->settings->get('llm.workflow.timeout'));

            $chatId = uniqid(UserChatHistory::CHAT_WEB, true);
            $session->set('chatId', $chatId);

            // Nettoyage des conversations vides de l'utilisateur
            $userId = (string) $session->get(Auth::USERID);
            if ($userId !== '') {
                $this->entityManager->getRepository(ChatHistoryEntity::class)->deleteEmptyConversations($userId);
            }

            $openingMessage = $this->brainRegistry->get($currentBrain, $session)->getOpeningText();
            $assistantMessage = new AssistantMessage($openingMessage)
                ->addMetadata('timestamp', new \DateTimeImmutable()->format(\DateTimeInterface::ATOM));
            $userChatHistory->replaceDisplayMessages([$assistantMessage]);
            $userChatHistory->replaceMessages([]);
            $messages = $userChatHistory->getFormattedMessages('stream');

            // On configure le Workflow par défaut au premier chat
            if ($this->settings->get('comfyui.enabled') === true && $currentComfyuiWorkflow === '') {
                $currentComfyuiWorkflow = $this->comfyUIWorkflowRegistry->getDefaultSlug() ?? '';
                $session->set(ComfyUIWorkflowRegistry::SESSION_KEY, $currentComfyuiWorkflow);
            }
        } else {
            $messages = $userChatHistory->getFormattedMessages('stream');
            $userChatHistory->validateMessageSequences();
        }

        $meta = $this->brainRegistry->getMeta($currentBrain);

        // Generate a per-tab stream session ID for SSE binding
        $streamSessionId = uniqid('sess-', true);

        return $this->twig->render($response, 'chat.twig', [
            'time' => $time,
            'messages' => $messages,
            'current_chat_id' => $chatId,
            'stream_session_id' => $streamSessionId,
            'uinfo' => $session->get(Auth::USERINFO),
            'layout_mode' => $layoutMode,
            'brain_info' => $meta,
            'current_brain' => $currentBrain,
            'brains' => $this->brainRegistry->list(),
            'comfyui_enabled' => $comfyuiEnabled,
            'comfyui_workflows' => $comfyuiWorkflows,
            'current_comfyui_workflow' => $currentComfyuiWorkflow,
        ]);
    }
}
