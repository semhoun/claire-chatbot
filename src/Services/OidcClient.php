<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use League\OAuth2\Client\OptionProvider\HttpBasicAuthOptionProvider;
use League\OAuth2\Client\OptionProvider\PostAuthOptionProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use Monolog\Logger;
use Odan\Session\SessionInterface;

final class OidcClient
{
    private bool $enabled = false;

    private array $discovery;

    private readonly GenericProvider $genericProvider;

    private readonly string $redirectUri;

    private string $tokenAuthMethod;

    private readonly array $scopes;

    public function __construct(
        private readonly Settings $settings,
        private readonly Logger $logger
    ) {
        $wellKnownUrl = $this->settings->get('oidc.well_known_url');
        $clientId = $this->settings->get('oidc.client_id');
        $clientSecret = $this->settings->get('oidc.client_secret');
        $this->scopes = $this->settings->get('oidc.scopes');
        $this->redirectUri = $this->settings->get('oidc.redirect_uri_base') .  '/auth/callback';

        if ($wellKnownUrl === '' || $clientId === '') {
            return;
        }

        $this->enabled = true;

        $client = new Client(['timeout' => 5.0]);
        $response = $client->get($wellKnownUrl);
        $this->discovery = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        // Determine token endpoint client authentication method
        $supported = array_map(strval(...), (array) ($this->discovery['token_endpoint_auth_methods_supported'] ?? []));
        // Auto-detect with sensible default
        if (in_array('client_secret_basic', $supported, true)) {
            $this->tokenAuthMethod = 'client_secret_basic';
        } elseif (in_array('client_secret_post', $supported, true)) {
            $this->tokenAuthMethod = 'client_secret_post';
        } else {
            $this->tokenAuthMethod = 'client_secret_basic';
        }

        $optionProvider = $this->tokenAuthMethod === 'client_secret_post'
            ? new PostAuthOptionProvider()
            : new HttpBasicAuthOptionProvider();

        $this->genericProvider = new GenericProvider([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri' => $this->redirectUri,
            'urlAuthorize' => $this->discovery['authorization_endpoint'] ?? '',
            'urlAccessToken' => $this->discovery['token_endpoint'] ?? '',
            'urlResourceOwnerDetails' => $this->discovery['userinfo_endpoint'] ?? '',
            // Ensure scopes are space-delimited per OIDC (encoded as '+')
            'scopeSeparator' => ' ',
            'scopes' => $this->scopes,
            'optionProvider' => $optionProvider,
        ]);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getDefaultUser(): array
    {
        return $this->settings->get('oidc.default_user');
    }

    public function getAuthorizationUrl(SessionInterface $session): string
    {
        $options = [];
        $options['scope'] = $this->scopes;
        $authUrl = $this->genericProvider->getAuthorizationUrl($options);
        $state = $this->genericProvider->getState();
        $session->set('oidc_state', $state);
        return $authUrl;
    }

    /**
     * @return array{logged:bool,uinfo?:array<string,mixed>} Normalized outcome
     */
    public function handleCallback(SessionInterface $session, array $queryParams): array
    {
        if (! isset($queryParams['state']) || $session->get('oidc_state') !== $queryParams['state']) {
            return ['logged' => false];
        }

        if (! isset($queryParams['code'])) {
            return ['logged' => false];
        }

        try {
            $accessToken = $this->genericProvider->getAccessToken('authorization_code', [
                'code' => $queryParams['code'],
                'redirect_uri' => $this->redirectUri,
            ]);
        } catch (IdentityProviderException) {
            // Note: do not leak provider internals to user; simply fail auth.
            // You can enable DEBUG_MODE to see detailed errors via Slim error handler.
            return ['logged' => false];
        } catch (\Throwable) {
            return ['logged' => false];
        }

        // Fetch user info from userinfo endpoint
        $request = $this->genericProvider->getAuthenticatedRequest(
            'GET',
            $this->discovery['userinfo_endpoint'] ?? '',
            $accessToken
        );
        $client = $this->genericProvider->getHttpClient();
        $response = $client->send($request);
        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data)) {
            return ['logged' => false];
        }

        $this->logger->info('OIDC user info', $data);

        // Normalize
        $uinfo = [
            'id' => $data['sub'] ?? null,
            'firstName' => $data['given_name'] ?? null,
            'lastName' => $data['family_name'] ?? null,
            'username' => $data['preferred_username'] ?? null,
            'name' => $data['name'] ?? trim(($data['given_name'] ?? '') . ' ' . ($data['family_name'] ?? '')),
            'email' => $data['email'] ?? null,
        ];

        return [
            'logged' => true,
            'uinfo' => $uinfo,
        ];
    }
}
