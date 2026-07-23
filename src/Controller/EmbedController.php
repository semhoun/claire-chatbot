<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Brain\LongTermMemory;
use App\Entity\ChatHistory as ChatHistoryEntity;
use App\Job\Web\StartThreadJob;
use App\Services\Auth;
use App\Services\ComfyUIWorkflowRegistry;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\Session\Trait\SessionFromRequest;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class EmbedController
{
    use SessionFromRequest;

    public function __construct(
        private Twig $twig,
        private BrainRegistry $brainRegistry,
        private EntityManagerInterface $entityManager,
        private ComfyUIWorkflowRegistry $comfyUIWorkflowRegistry,
        private Settings $settings,
        private QueueDispatcherInterface $queueDispatcher,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        $sessionId = uniqid('sess-', true);
        $threadId = uniqid(UserChatHistory::CHAT_WEB, true);

        $this->entityManager
            ->getRepository(ChatHistoryEntity::class)
            ->deleteEmptyConversations((string) $session->get(Auth::USERID));

        $this->queueDispatcher->dispatch(
            StartThreadJob::class,
            [
                'threadId' => $threadId,
                'sessionId' => $sessionId,
                'session' => $session->all(),
            ],
            $this->settings->get('queue.defaultQueue')
        );

        $comfyuiEnabled = $this->settings->get('tools.comfyui.enabled') === true;
        $currentComfyuiWorkflow = '';
        if ($comfyuiEnabled) {
            $currentComfyuiWorkflow = (string) $session->get(
                ComfyUIWorkflowRegistry::SESSION_KEY,
                ''
            );

            if ($currentComfyuiWorkflow === '') {
                $defaultWorkflow =
                    $this->comfyUIWorkflowRegistry->getDefaultSlug() ?? '';
                $session->set(
                    ComfyUIWorkflowRegistry::SESSION_KEY,
                    $defaultWorkflow
                );
            }
        }

        return $this->twig->render($response, 'embed.twig', [
            'time' => new \DateTime()->format('H:i'),
            'current_thread_id' => $threadId,
            'stream_session_id' => $sessionId,
            'uinfo' => $session->get(Auth::USERINFO),
            'layout_mode' => $session->get('layout_mode'),
            'brain_info' => $this->brainRegistry->getMeta(
                $session->get('brain_avatar')
            ),
            'current_brain' => $session->get('brain_avatar'),
            'long_term_memory_enabled' => $session->get(LongTermMemory::SESSION_KEY, false),
            'brains' => $this->brainRegistry->list(),
            'comfyui_enabled' => $comfyuiEnabled,
            'comfyui_workflows' => $comfyuiEnabled
                ? $this->comfyUIWorkflowRegistry->list()
                : [],
            'current_comfyui_workflow' => $currentComfyuiWorkflow,
        ]);
    }
}
