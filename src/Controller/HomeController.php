<?php

declare(strict_types=1);

namespace App\Controller;

use App\Renderer\VueShell;
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

final readonly class HomeController
{
    use SessionFromRequest;

    public function __construct(
        private VueShell $vueShell,
        private EntityManagerInterface $entityManager,
        private FrontendConfigFactory $frontendConfigFactory,
        private Settings $settings,
        private QueueDispatcherInterface $queueDispatcher,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        if (! str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return $this->vueShell->respond($response, [
                'page' => 'app', 'baseUrl' => (string) $request->getAttribute('base_url'),
            ]);
        }

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
        $config['baseUrl'] = (string) $request->getAttribute('base_url');
        $response->getBody()->write(json_encode($config, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }
}
