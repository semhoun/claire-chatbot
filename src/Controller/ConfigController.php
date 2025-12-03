<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

readonly class ConfigController
{
    public function __construct(
        private SessionInterface $session,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Set current chat mode in session ("chat" | "stream")
     */
    public function chatMode(Request $request, Response $response): Response
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $mode = (string) ($data['mode'] ?? '');
        if (! in_array($mode, ['chat', 'stream'], true)) {
            return $response->withStatus(400);
        }

        $this->session->set('chat_mode', $mode);
        $user = $this->entityManager->getRepository(User::class)->find($this->session->get('userId'));
        if ($user === null) {
            return $response->withStatus(404);
        }

        $params = $user->params ?? [];
        $params['chat_mode'] = $mode;
        $user->params = $params;
        $this->entityManager->flush();

        // HTMX friendly: no content needed
        return $response->withStatus(204);
    }
}
