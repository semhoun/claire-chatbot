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

final readonly class EmbedController
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
        if ($session->get(Auth::AUTHENTICATED, false) !== true) {
            return $response->withStatus(401);
        }

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

        $config = $this->frontendConfigFactory->create(
            $session,
            'embed',
            $threadId,
            $sessionId
        );
        return $this->twig->render($response, 'embed.twig', [
            'base_url' => (string) $request->getAttribute('base_url'),
            'config' => $config,
        ])->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
