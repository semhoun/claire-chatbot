<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Services\ChatStopRequests;
use App\Services\Session\Trait\SessionFromRequest;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class ChatStopController
{
    use SessionFromRequest;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ChatStopRequests $stopRequests,
        private Settings $settings,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $response = $response->withHeader('Cache-Control', 'no-store');
        if ($this->settings->get('llm.stop.enabled', false) !== true) {
            return $response->withStatus(404);
        }
        $user = $this->entityManager->getRepository(User::class)->getCurrentUser($this->getSession($request));
        if ($user === null) {
            return $response->withStatus(401);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (! is_string($body['threadId'] ?? null) || $body['threadId'] === '' || strlen($body['threadId']) > 128
            || ! is_string($body['generationId'] ?? null)
            || preg_match('/\A[A-Za-z][A-Za-z0-9_.:-]{0,127}\z/', $body['generationId']) !== 1) {
            return $response->withStatus(400);
        }
        // Channel and owner are never accepted from the body. The SQL identity proves acceptance/ownership.
        $record = $this->stopRequests->request(
            (string) $user->getId(),
            $body['threadId'],
            'web',
            $body['generationId'],
        );
        if ($record === null) {
            return $response->withStatus(404);
        }
        $pending = $record['status'] === 'accepted';
        $response->getBody()->write(json_encode([
            'status' => $pending ? 'queued' : $record['status'],
        ], JSON_THROW_ON_ERROR));
        return $response->withStatus($pending ? 202 : 200)->withHeader('Content-Type', 'application/json');
    }
}
