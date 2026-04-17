<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Session\SessionInterface;
use GuzzleHttp\Client;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Validator;
use League\OAuth2\Client\OptionProvider\HttpBasicAuthOptionProvider;
use League\OAuth2\Client\OptionProvider\PostAuthOptionProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface as Logger;

final class OidcClient
{
    /** @var array<string, mixed> */
    private array $discovery;

    /** @var array<string, mixed> */
    private array $jwks;

    private readonly GenericProvider $genericProvider;

    private readonly string $redirectUri;

    private readonly string $tokenAuthMethod;

    /** @var array<int, string> */
    private readonly array $scopes;

    public function __construct(
        private readonly Logger $logger,
        private readonly Settings $settings
    ) {
        $wellKnownUrl = $this->settings->get('oidc.well_known_url');
        $clientId = $this->settings->get('oidc.client_id');

        $this->scopes = $this->settings->get('oidc.scopes');
        $this->redirectUri = $this->settings->get('base_url') . '/auth/callback';

        $this->discovery = $this->fetchDiscovery($wellKnownUrl);
        $this->jwks = $this->fetchJwks($this->discovery['jwks_uri'] ?? '');
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
        try {
            $validationResult = $this->validateCallback($session, $queryParams);
            if ($validationResult !== null) {
                return $validationResult;
            }

            $accessToken = $this->fetchAccessToken($queryParams['code']);
            if ($accessToken === null) {
                return ['logged' => false];
            }

            if ($accessToken->hasExpired()) {
                return ['logged' => false];
            }

            $idToken = $accessToken->getValues()['id_token'] ?? null;
            if ($idToken === null) {
                return ['logged' => false];
            }

            $parser = new Parser(new JoseEncoder());
            $token = $parser->parse($idToken);

            if (!$this->validateToken($token)) {
                return ['logged' => false];
            }

            $tokenInfo = $token->claims()->all();

            $session->delete('oidc_state');

            return [
                'logged' => true,
                'id' => $tokenInfo['sub'] ?? null,
                'data' => $this->normalizeUserData($tokenInfo),
            ];
        }
        catch (\Exception $e) {
            $this->logger->info('Error processing OIDC callback', ['exception' => $e]);
            return ['logged' => false];
        }
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

    /**
     * @return array<string, mixed>
     */
    private function fetchJwks(string $jwksUri): array
    {
        if ($jwksUri === '') {
            return [];
        }

        $client = new Client(['timeout' => 5.0]);
        $response = $client->get($jwksUri);

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function validateToken(Token $token): bool
    {
        $kid = $token->headers()->get('kid');
        $alg = $token->headers()->get('alg');

        if ($alg !== 'RS256') {
            $this->logger->error('Unsupported ID token algorithm', ['alg' => $alg]);
            return false;
        }

        $keyData = null;
        foreach ($this->jwks['keys'] ?? [] as $key) {
            if ($key['kid'] === $kid) {
                $keyData = $key;
                break;
            }
        }

        if ($keyData === null) {
            $this->logger->error('JWK not found for kid', ['kid' => $kid]);
            return false;
        }

        if (! isset($keyData['n'], $keyData['e'])) {
            $this->logger->error('Invalid JWK format');
            return false;
        }

        $publicKey = $this->convertJwkToPem($keyData['n'], $keyData['e']);
        $signer = new Sha256();
        $key = InMemory::plainText($publicKey);

        $validator = new Validator();
        if (!$validator->validate($token, new SignedWith($signer, $key))) {
            $this->logger->info('Invalid token signature', ['token' => $token]);
            return false;
        }
        if (!$validator->validate($token, new LooseValidAt(SystemClock::fromUTC()))) {
            $this->logger->info('Token is not valid at current time', ['token' => $token]);
            return false;
        }
        return true;
    }

    private function convertJwkToPem(string $n, string $e): string
    {
        $n = base64_decode(strtr($n, '-_', '+/'), true);
        $e = base64_decode(strtr($e, '-_', '+/'), true);

        if ($n === false || $e === false) {
            throw new \RuntimeException('Failed to decode JWK');
        }

        $buildDer = function ($type, $value) {
            $len = strlen($value);
            if ($len < 128) {
                $lenField = chr($len);
            } else {
                $lenField = dechex($len);
                if (strlen($lenField) % 2 !== 0) {
                    $lenField = '0' . $lenField;
                }
                $lenField = pack('H*', $lenField);
                $lenField = chr(0x80 | strlen($lenField)) . $lenField;
            }
            return chr($type) . $lenField . $value;
        };

        $n = ltrim($n, "\x00");
        if (ord($n[0]) & 0x80) {
            $n = "\x00" . $n;
        }
        $e = ltrim($e, "\x00");
        if (ord($e[0]) & 0x80) {
            $e = "\x00" . $e;
        }

        $rsaPublicKey = $buildDer(0x30, $buildDer(0x02, $n) . $buildDer(0x02, $e));

        $algorithmIdentifier = pack('H*', '300d06092a864886f70d0101010500');
        $publicKeyInfo = $buildDer(0x30, $algorithmIdentifier . $buildDer(0x03, "\x00" . $rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n" .
            wordwrap(base64_encode($publicKeyInfo), 64, "\n", true) .
            "\n-----END PUBLIC KEY-----";
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
            'email' => $tokenInfo['email'] ?? null,
        ];

        if (empty($data['lastName']) && empty($data['firstName']) && ! empty($tokenInfo['name'])) {
            $data['firstName'] = $tokenInfo['name'];
        }

        return $data;
    }
}
