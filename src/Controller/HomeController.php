<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\ChatHistory\UserChatHistory;
use App\Entity\ChatHistory as ChatHistoryEntity;
use App\Job\Web\StartThreadJob;
use App\Services\Auth;
use App\Services\FrontendConfigFactory;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\Session\Trait\SessionFromRequest;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class HomeController
{
    use SessionFromRequest;

    public function __construct(
        private Twig $twig,
        private EntityManagerInterface $entityManager,
        private FrontendConfigFactory $frontendConfigFactory,
        private Settings $settings,
        private QueueDispatcherInterface $queueDispatcher,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        $sessionId = uniqid('sess-', true);
        $userId = (string) $session->get(Auth::USERID);
        $entityRepository = $this->entityManager->getRepository(ChatHistoryEntity::class);

        $entityRepository->deleteEmptyConversations($userId);

        $currentThreadId = (string) $session->get('threadId');
        $history = $currentThreadId !== ''
            ? $entityRepository->getCurrentUserChatHistory($session, $currentThreadId)
            : null;
        $history ??= $entityRepository->getLatestHistory($userId);
        $threadId = $history?->getThreadId()
            ?? uniqid(UserChatHistory::CHAT_WEB, true);

        $session->set('threadId', $threadId);
        if ($history === null) {
            $this->queueDispatcher->dispatch(
                StartThreadJob::class,
                [
                    'threadId' => $threadId,
                    'sessionId' => $sessionId,
                    'session' => $session->all(),
                ],
                $this->settings->get('queue.defaultQueue')
            );
        }

        $config = $this->frontendConfigFactory->create(
            $session,
            'normal',
            $threadId,
            $sessionId
        );
        return $this->twig->render($response, 'app.twig', [
            'base_url' => (string) $request->getAttribute('base_url'),
            'config' => $config,
        ])->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
