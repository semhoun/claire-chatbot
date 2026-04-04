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
        $mode = $session->get('chat_mode');
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
            $messages = $userChatHistory->getFormattedMessages($mode);

            // On configure le Workflow par défaut au premier chat
            if ($this->settings->get('comfyui.enabled') === true && $currentComfyuiWorkflow === '') {
                $currentComfyuiWorkflow = (string) ($this->comfyUIWorkflowRegistry->getDefaultSlug() ?? '');
                $session->set(ComfyUIWorkflowRegistry::SESSION_KEY, $currentComfyuiWorkflow);
            }
        }
        else {
            $messages = $userChatHistory->getFormattedMessages($mode);
            $userChatHistory->validateMessageSequences();
        }

        // Métadonnées du brain courant via la registry
        try {
            $meta = $this->brainRegistry->getMeta($currentBrain);
        } catch (\InvalidArgumentException) {
            // Fallback sur le brain par défaut si le slug n'est pas valide
            $currentBrain = $this->settings->get('llm.defaultBrain');
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
            'comfyui_enabled' => $comfyuiEnabled,
            'comfyui_workflows' => $comfyuiWorkflows,
            'current_comfyui_workflow' => $currentComfyuiWorkflow,
        ]);
    }
}
