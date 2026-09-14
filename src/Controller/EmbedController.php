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

final readonly class EmbedController
{
    use SessionFromRequest;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FrontendConfigFactory $frontendConfigFactory,
        private Settings $settings,
        private QueueDispatcherInterface $queueDispatcher,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        if ($session->get(Auth::AUTHENTICATED, false) !== true) {
            return $response->withStatus(401);
        }

        $sessionId = uniqid('sess-', true);
        $threadId = uniqid(UserChatHistory::CHAT_WEB, true);

        $this->entityManager
            ->getRepository(ChatHistoryEntity::class)
            ->deleteEmptyConversations((string) $session->get(Auth::USERID));

        $config = $this->frontendConfigFactory->create(
            $session,
            'embed',
            $threadId,
            $sessionId
        );
        $this->queueDispatcher->dispatch(
            StartThreadJob::class,
            [
                'threadId' => $threadId,
                'sessionId' => $sessionId,
                'session' => $session->all(),
            ],
            $this->settings->get('queue.defaultQueue')
        );

        $config['baseUrl'] = (string) $request->getAttribute('base_url');
        $response->getBody()->write(json_encode($config, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }
}
