<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\Agent;
use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Entity\ChatHistory as ChatHistoryEntity;
use App\Services\Auth;
use App\Services\ComfyUIWorkflowRegistry;
use App\Services\Session\SessionInterface;
use App\Services\Session\Trait\SessionFromRequest;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Slim\Views\Twig;

final readonly class HomeController
{
    use SessionFromRequest;

    public function __construct(
        private Logger $logger,
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
        $userChatHistory = $this->createUserChatHistory($session);
        $chatId = $this->resolveChatId($session, $userChatHistory);

        if ($chatId === null) {
            $chatId = $this->initializeNewChat($session, $userChatHistory);
        } else {
            $userChatHistory->validateMessageSequences();
        }

        $comfyuiEnabled = $this->settings->get('comfyui.enabled') === true;
        $currentComfyuiWorkflow = (string) $session->get(ComfyUIWorkflowRegistry::SESSION_KEY, '');

        return $this->twig->render($response, 'chat.twig', [
            'time' => new \DateTime()->format('H:i'),
            'current_chat_id' => $chatId,
            'stream_session_id' => uniqid('sess-', true),
            'uinfo' => $session->get(Auth::USERINFO),
            'layout_mode' => $session->get('layout_mode'),
            'brain_info' => $this->brainRegistry->getMeta($session->get('brain_avatar')),
            'current_brain' => $session->get('brain_avatar'),
            'brains' => $this->brainRegistry->list(),
            'comfyui_enabled' => $comfyuiEnabled,
            'comfyui_workflows' => $comfyuiEnabled ? $this->comfyUIWorkflowRegistry->list() : [],
            'current_comfyui_workflow' => $currentComfyuiWorkflow,
        ]);
    }

    private function createUserChatHistory(SessionInterface $session): UserChatHistory
    {
        return new UserChatHistory(
            session: $session,
            pdo: $this->entityManager->getConnection()->getNativeConnection(),
            contextWindow: $this->settings->get('llm.openai.contextWindow')
        );
    }

    private function resolveChatId(SessionInterface $session, UserChatHistory $userChatHistory): ?string
    {
        $chatId = $session->get('chatId');

        if ($chatId !== null && count($userChatHistory->getDisplayMessages()) > 1) {
            return $chatId;
        }

        return null;
    }

    private function initializeNewChat(SessionInterface $session, UserChatHistory $userChatHistory): string
    {
        set_time_limit((int) $this->settings->get('llm.workflow.timeout'));
        $this->cleanupEmptyConversations($session);

        $chatId = uniqid(UserChatHistory::CHAT_WEB, true);
        $session->set('chatId', $chatId);
        $userChatHistory->setThreadId($chatId);

        $agent = $this->brainRegistry->get($session->get('brain_avatar'), $session);
        $this->setOpeningMessage($userChatHistory, $agent);
        $this->setDefaultWorkflow($session);

        return $chatId;
    }

    private function cleanupEmptyConversations(SessionInterface $session): void
    {
        $userId = (string) $session->get(Auth::USERID);
        if ($userId !== '') {
            $this->entityManager->getRepository(ChatHistoryEntity::class)->deleteEmptyConversations($userId);
        }
    }

    private function setOpeningMessage(UserChatHistory $userChatHistory, Agent $agent): void
    {
        $openingMessage = $agent->getOpeningText();
        $assistantMessage = new AssistantMessage($openingMessage)
            ->addMetadata('timestamp', new \DateTimeImmutable()->format(\DateTimeInterface::ATOM));
        $userChatHistory->replaceDisplayMessages([$assistantMessage]);
        $userChatHistory->replaceMessages([]);

        $this->logger->debug('new messages', ['messages' => $userChatHistory->getFormattedMessages()]);
    }

    private function setDefaultWorkflow(SessionInterface $session): void
    {
        $currentComfyuiWorkflow = (string) $session->get(ComfyUIWorkflowRegistry::SESSION_KEY, '');
        if ($this->settings->get('comfyui.enabled') === true && $currentComfyuiWorkflow === '') {
            $defaultWorkflow = $this->comfyUIWorkflowRegistry->getDefaultSlug() ?? '';
            $session->set(ComfyUIWorkflowRegistry::SESSION_KEY, $defaultWorkflow);
        }
    }
}
