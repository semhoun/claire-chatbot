<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Auth;
use App\Services\MiniToken;
use App\Services\OidcClient;
use App\Services\Session\SessionManagerInterface;
use App\Services\Session\SessionInterface;
use App\Services\Session\Trait\SessionFromRequest;
use App\Services\Settings;
use DateTimeImmutable;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Slim\Views\Twig;

final readonly class AuthController
{
    use SessionFromRequest;

    public function __construct(
        private \Psr\Log\LoggerInterface $logger,
        private OidcClient $oidcClient,
        private Auth $auth,
        private MiniToken $miniToken,
        private Settings $settings,
        private Twig $twig,
    ) {
    }

    public function ssoRedirect(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $authUrl = $this->oidcClient->getAuthorizationUrl($session);
        return $response->withHeader('Location', $authUrl)->withStatus(302);
    }

    public function ssoCallback(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        if (! $session instanceof SessionInterface) {
            return $response->withStatus(500);
        }

        $result = $this->oidcClient->handleCallback($session, $request->getQueryParams());

        if (! ($result['logged'] ?? false)) {
            // If the OAuth provider returned an error, display an error page
            if (isset($result['error'])) {
                $errorDescription = $result['error_description'] ?? 'Authorization failed';

                return $this->twig->render($response, 'error/default.twig', [
                    'code' => 403,
                    'title' => 'Accès refusé: ' . $result['error'],
                    'message' => $errorDescription,
                ])->withStatus(403);
            }

            // Auth uniquement via SSO: en cas d'échec, on renvoie vers l'init SSO
            return $response->withHeader('Location', '/auth/sso')->withStatus(302);
        }

        if ($result['id'] === null) {
            $this->logger->warning('No user id returned from SSO');
            return $response->withStatus(500);
        }

        $this->auth->login($session, $result['id'], $result['data']);

        $sessionToken = $this->buildSessionToken($session);

        // Render callback page that stores token client-side then redirects
        // This avoids losing the session token on a 302 redirect
        return $this->twig->render($response, 'auth_callback.twig', [
            'redirect_url' => '/?token=' . urlencode($sessionToken),
            'session_token' => $sessionToken,
        ]);
    }

    public function logout(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        if (! $session instanceof SessionInterface) {
            return $response->withStatus(500);
        }

        $this->auth->logout($session);
        return $response->withStatus(302)->withHeader('Location', '/');
    }

    public function minitoken(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        $userId = (string) $session->get(Auth::USERID, '');
        if ($userId === '' || $userId === '0') {
            return $response->withStatus(401);
        }

        $lifetime = (int) $this->settings->get('session.lifetime');
        $token = $this->miniToken->generate($session, $lifetime);

        $response->getBody()->write(json_encode([
            'minitoken' => $token,
            'expiresIn' => $lifetime,
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }

    private function buildSessionToken(SessionInterface $session): string
    {
        $secret = (string) $this->settings->get('session.jwt.secret');
        if ($secret === '') {
            throw new RuntimeException('JWT secret key is not configured');
        }

        $sessionData = $session instanceof SessionManagerInterface
            ? ($session->getStorageAsArray() ?? [])
            : $session->all();
        $sessionId = $session instanceof SessionManagerInterface
            ? $session->getId()
            : (string) ($session->get(Auth::USERID, '') ?: hash('sha256', (string) json_encode($sessionData, JSON_THROW_ON_ERROR)));

        $inMemory = InMemory::plainText($secret);
        $sha256 = new Sha256();
        $now = new DateTimeImmutable();
        $lifetime = (int) $this->settings->get('session.lifetime');

        $token = Builder::new(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify(sprintf('+%d seconds', $lifetime)))
            ->identifiedBy(Uuid::uuid4()->toString())
            ->relatedTo($sessionId)
            ->withClaim('data', $sessionData)
            ->getToken($sha256, $inMemory);

        return $token->toString();
    }
}
