<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception;
use App\Services\OidcClient;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class AuthController
{
    public function __construct(
        private SessionInterface $session,
        private OidcClient $oidcClient,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function ssoRedirect(Request $request, Response $response): Response
    {
        if (! $this->oidcClient->isEnabled()) {
            $this->userLogged($this->oidcClient->getDefaultUser());
            return $response->withStatus(302)->withHeader('Location', '/');
        }

        $authUrl = $this->oidcClient->getAuthorizationUrl($this->session);
        return $response->withHeader('Location', $authUrl)->withStatus(302);
    }

    public function ssoCallback(Request $request, Response $response): Response
    {
        $result = $this->oidcClient->handleCallback($this->session, $request->getQueryParams());
        if (! ($result['logged'] ?? false)) {
            // Auth uniquement via SSO: en cas d'échec, on renvoie vers l'init SSO
            return $response->withHeader('Location', '/auth/sso')->withStatus(302);
        }

        $this->userLogged($result['uinfo']);

        return $response->withStatus(302)->withHeader('Location', '/');
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->session->set('logged', false);
        $this->session->set('uinfo', null);
        return $response->withStatus(302)->withHeader('Location', '/');
    }

    /**
     * @throws \Exception
     */
    private function userLogged(array $uinfo): void
    {
        $this->session->set('logged', true);
        $this->session->set('userId', $uinfo['id']);

        // Vérifier l'existence de l'utilisateur en base via son id (sub OIDC).
        // Le créer s'il n'existe pas, sinon mettre à jour les infos de base.
        $userId = (string) ($uinfo['id'] ?? '');
        if ($userId === '') {
            throw new Exception('User id not provided by OIDC provider:');
        }

        $this->session->set('uinfo', $uinfo);

        try {
            /** @var User|null $user */
            $user = $this->entityManager->getRepository(User::class)->find($userId);
            if ($user === null) {
                $user = new User();
                $user->setId($userId);
                $this->entityManager->persist($user);
                $this->entityManager->flush();
            }

            $user = $this->entityManager->getRepository(User::class)->find($userId);
            $user->setFirstName($uinfo['firstName']);
            $user->setLastName($uinfo['lastName']);
            $user->setEmail($uinfo['email']);
            if ($uinfo['firstName'] === null && $uinfo['lastName'] === null && $uinfo['name'] !== null) {
                $user->setFirstName($uinfo['name']);
            }

            $this->entityManager->flush();

            foreach ($user->getParams() ?? [] as $key => $value) {
                $this->session->set($key, $value);
            }
        } catch (\Exception $exception) {
            throw new Exception('User not found in database: ' . $exception->getMessage());
        }
    }
}
