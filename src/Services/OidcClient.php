<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Session\SessionInterface;
use GuzzleHttp\Client;
use League\OAuth2\Client\OptionProvider\HttpBasicAuthOptionProvider;
use League\OAuth2\Client\OptionProvider\PostAuthOptionProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;

final class OidcClient
{
    private bool $enabled = false;

    /** @var array<string, mixed> */
    private array $discovery;

    private readonly GenericProvider $genericProvider;

    private readonly string $redirectUri;

    private readonly string $tokenAuthMethod;

    /** @var array<int, string> */
    private readonly array $scopes;

    public function __construct(
        private readonly Settings $settings
    ) {
        $wellKnownUrl = $this->settings->get('oidc.well_known_url');
        $clientId = $this->settings->get('oidc.client_id');

        $this->scopes = $this->settings->get('oidc.scopes');
        $this->redirectUri = $this->settings->get('base_url') . '/auth/callback';

        $this->discovery = $this->fetchDiscovery($wellKnownUrl);
        $this->tokenAuthMethod = $this->detectAuthMethod();
        $this->genericProvider = $this->createGenericProvider($clientId);
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
     * @param array<string, mixed> $queryParams
     *
     * @return array{logged:bool,id?:string,data?:array<string,mixed>,error?:string,error_description?:string} Normalized outcome
     */
    public function handleCallback(SessionInterface $session, array $queryParams): array
    {
        $validationResult = $this->validateCallback($session, $queryParams);
        if ($validationResult !== null) {
            return $validationResult;
        }

        $accessToken = $this->fetchAccessToken($queryParams['code']);
        if ($accessToken === null) {
            return ['logged' => false];
        }

        $tokenInfo = $this->fetchUserInfo($accessToken);
        if ($tokenInfo === null) {
            return ['logged' => false];
        }

        $session->delete('oidc_state');

        return [
            'logged' => true,
            'id' => $tokenInfo['sub'] ?? null,
            'data' => $this->normalizeUserData($tokenInfo),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDiscovery(string $wellKnownUrl): array
    {
        $client = new Client(['timeout' => 5.0]);
        $response = $client->get($wellKnownUrl);

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function detectAuthMethod(): string
    {
        $supported = array_map(strval(...), (array) ($this->discovery['token_endpoint_auth_methods_supported'] ?? []));

        if (in_array('client_secret_basic', $supported, true)) {
            return 'client_secret_basic';
        }

        if (in_array('client_secret_post', $supported, true)) {
            return 'client_secret_post';
        }

        return 'client_secret_basic';
    }

    private function createGenericProvider(string $clientId): GenericProvider
    {
        $optionProvider = $this->tokenAuthMethod === 'client_secret_post'
            ? new PostAuthOptionProvider()
            : new HttpBasicAuthOptionProvider();

        return new GenericProvider([
            'clientId' => $clientId,
            'clientSecret' => $this->settings->get('oidc.client_secret'),
            'redirectUri' => $this->redirectUri,
            'urlAuthorize' => $this->discovery['authorization_endpoint'] ?? '',
            'urlAccessToken' => $this->discovery['token_endpoint'] ?? '',
            'urlResourceOwnerDetails' => $this->discovery['userinfo_endpoint'] ?? '',
            'scopeSeparator' => ' ',
            'scopes' => $this->scopes,
            'optionProvider' => $optionProvider,
        ]);
    }

    /**
     * @param array<string, mixed> $queryParams
     *
     * @return array{logged:bool,error?:string,error_description?:string}|null
     */
    private function validateCallback(SessionInterface $session, array $queryParams): ?array
    {
        if (! isset($queryParams['state']) || $session->get('oidc_state') !== $queryParams['state']) {
            return ['logged' => false];
        }

        if (isset($queryParams['error'])) {
            return [
                'logged' => false,
                'error' => $queryParams['error'],
                'error_description' => $queryParams['error_description'] ?? null,
            ];
        }

        if (! isset($queryParams['code'])) {
            return ['logged' => false];
        }

        return null;
    }

    private function fetchAccessToken(string $code): ?object
    {
        try {
            return $this->genericProvider->getAccessToken('authorization_code', [
                'code' => $code,
                'redirect_uri' => $this->redirectUri,
            ]);
        } catch (IdentityProviderException|\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchUserInfo(object $accessToken): ?array
    {
        $request = $this->genericProvider->getAuthenticatedRequest(
            'GET',
            $this->discovery['userinfo_endpoint'] ?? '',
            $accessToken
        );
        $client = $this->genericProvider->getHttpClient();
        $response = $client->send($request);
        $tokenInfo = json_decode((string) $response->getBody(), true);

        return is_array($tokenInfo) ? $tokenInfo : null;
    }

    /**
     * @param array<string, mixed> $tokenInfo
     *
     * @return array<string, mixed>
     */
    private function normalizeUserData(array $tokenInfo): array
    {
        $data = [
            'firstName' => $tokenInfo['given_name'] ?? null,
            'lastName' => $tokenInfo['family_name'] ?? null,
            'username' => $tokenInfo['preferred_username'] ?? null,
            'displayName' => trim(($tokenInfo['given_name'] ?? '') . ' ' . ($tokenInfo['family_name'] ?? '')),
            'email' => $tokenInfo['email'] ?? null,
        ];

        if (empty($data['displayName']) && ! empty($tokenInfo['name'])) {
            $data['displayName'] = $tokenInfo['name'];
            $data['firstName'] = $tokenInfo['name'];
        }

        return $data;
    }
}
