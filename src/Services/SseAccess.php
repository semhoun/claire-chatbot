<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Session\ArraySession;
use App\Services\Session\SessionInterface;
use Doctrine\ORM\EntityManagerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;

final readonly class SseAccess
{
    public function __construct(
        private JwtTokenService $tokens,
        private RememberSession $rememberSession,
        private EntityManagerInterface $entityManager,
        private ChatGenerationState $generationState,
        private Settings $settings,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function authenticate(array $input): ArraySession
    {
        $basePath = rtrim((string) parse_url((string) $this->settings->get('base_url'), PHP_URL_PATH), '/');
        $path = $basePath . '/brain/stream';
        $request = new ServerRequestFactory()->createServerRequest('GET', $path)
            ->withAttribute(RouteContext::BASE_PATH, $basePath);
        if (count($input) !== 6 || ($input['method'] ?? null) !== 'GET' || ($input['path'] ?? null) !== $path
            || ! in_array($input['credentialType'] ?? null, ['header', 'capability'], true)
            || ! is_string($input['credential'] ?? null) || $input['credential'] === ''
            || ! $this->tokens->validResources([
                [
                    'type' => 'stream',
                    'threadId' => $input['threadId'] ?? null,
                    'sessionId' => $input['sessionId'] ?? null,
                ],
            ])) {
            throw new HttpBadRequestException($request);
        }
        $request = $request->withQueryParams(['threadId' => $input['threadId'], 'sessionId' => $input['sessionId']]);
        $credential = $input['credential'];
        $resource = $this->tokens->parseStreamToken($credential)
            ?? $this->tokens->parseResourcesToken($credential)
            ?? $this->tokens->parseFileToken($credential);
        $session = new ArraySession();
        $session->start();
        if ($resource !== null) {
            if (! ResourceRequestScope::matches($request, $resource)) {
                throw new HttpForbiddenException($request);
            }
            $session->set(Auth::USERID, $resource['userId']);
            $session->set(Auth::AUTHENTICATED, true);
            if (array_key_exists(RememberSession::ID, $resource)) {
                $session->set(RememberSession::ID, $resource[RememberSession::ID]);
                $session->set(RememberSession::EXPIRES, $resource[RememberSession::EXPIRES] ?? null);
            }
        } else {
            $parsed = $input['credentialType'] === 'header' ? $this->tokens->parseSessionToken($credential) : null;
            if ($parsed === null || ! is_array($parsed['sessionData'])) {
                throw new HttpUnauthorizedException($request);
            }
            // Retain only identity and revocation context, never the general session or token.
            foreach ([Auth::USERID, Auth::AUTHENTICATED, RememberSession::ID, RememberSession::EXPIRES] as $key) {
                if (array_key_exists($key, $parsed['sessionData'])) {
                    $session->set($key, $parsed['sessionData'][$key]);
                }
            }
        }
        $this->check($session, $input['threadId']);
        return $session;
    }

    public function check(SessionInterface $session, string $threadId): void
    {
        $request = new ServerRequestFactory()->createServerRequest('POST', '/snapshot');
        $userId = $session->get(Auth::USERID);
        if (! is_string($userId) || $userId === '' || $userId === '0'
            || $session->get(Auth::AUTHENTICATED) !== true || ! $this->rememberSession->valid($session)) {
            throw new HttpUnauthorizedException($request);
        }
        // Recheck both facts in one fresh SQL statement, never Doctrine's managed identity map.
        $access = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT h.user_id FROM account a LEFT JOIN chat_history h ON h.thread_id = ? WHERE a.id = ?',
            [$threadId, $userId],
        );
        if ($access === false) {
            throw new HttpUnauthorizedException($request);
        }
        if ($access['user_id'] !== null) {
            if ($access['user_id'] !== $userId) {
                throw new HttpForbiddenException($request);
            }
            return;
        }
        $state = $this->generationState->get($userId, $threadId);
        if (($state['messageId'] ?? '') === ''
            || ! in_array($state['status'] ?? '', ['queued', 'running', 'done', 'error'], true)) {
            throw new HttpForbiddenException($request);
        }
    }
}
