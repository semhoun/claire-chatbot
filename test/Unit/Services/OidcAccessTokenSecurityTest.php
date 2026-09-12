<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\OidcClient;
use App\Services\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;

final class OidcAccessTokenSecurityTest extends TestCase
{
    private function client(array $discovery, Client $http): OidcClient
    {
        $client = (new ReflectionClass(OidcClient::class))->newInstanceWithoutConstructor();
        foreach ([
            'logger' => new NullLogger(),
            'settings' => new Settings(['oidc' => [
                'client_id' => 'claire', 'client_secret' => 'secret',
                'access_token_client_ids' => ['claire', 'trusted-embed'],
            ]]),
            'discovery' => $discovery,
            'jwks' => [],
            'httpClient' => $http,
        ] as $name => $value) {
            (new ReflectionProperty(OidcClient::class, $name))->setValue($client, $value);
        }
        return $client;
    }

    public static function claims(): iterable
    {
        yield 'valid Claire' => [[], true];
        yield 'trusted embed' => [['client_id' => 'trusted-embed', 'aud' => ['claire', 'api']], true];
        yield 'inactive' => [['active' => false], false];
        yield 'string active' => [['active' => 'true'], false];
        yield 'wrong audience' => [['aud' => 'other'], false];
        yield 'missing audience' => [['aud' => null], false];
        yield 'wrong client' => [['client_id' => 'attacker'], false];
        yield 'missing client' => [['client_id' => null], false];
        yield 'expired' => [['exp' => 1], false];
        yield 'future' => [['nbf' => PHP_INT_MAX], false];
        yield 'bad issuer' => [['iss' => 'https://evil.test'], false];
        yield 'missing subject' => [['sub' => null], false];
    }

    #[DataProvider('claims')]
    public function testIntrospectionClaimsAndClientAuthentication(array $override, bool $accepted): void
    {
        $history = [];
        $claims = array_replace([
            'active' => true, 'aud' => 'claire', 'client_id' => 'claire', 'sub' => 'user-1',
        ], $override);
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode($claims, JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $client = $this->client(['introspection_endpoint' => 'https://issuer.test/introspect'],
            new Client(['handler' => $stack]));
        $result = $client->resolveUserFromSsoToken('opaque', 'access_token');
        self::assertSame($accepted, $result['logged']);
        self::assertCount(1, $history);
        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertSame('Basic ' . base64_encode('claire:secret'),
            $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('token=opaque&token_type_hint=access_token', (string) $history[0]['request']->getBody());
        self::assertFalse($history[0]['options']['allow_redirects']);
    }

    public function testNoIntrospectionNoFallbackAndExplicitOpaqueType(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([]));
        $stack->push(Middleware::history($history));
        $client = $this->client(['userinfo_endpoint' => 'https://issuer.test/userinfo'],
            new Client(['handler' => $stack]));
        self::assertSame('access_token_introspection_unavailable',
            $client->resolveUserFromSsoToken('opaque', 'access_token')['reason']);
        self::assertSame('explicit_access_token_type_required',
            $client->resolveUserFromSsoToken('opaque')['reason']);
        self::assertSame('id_token', $client->resolveUserFromSsoToken('invalid.jwt.token')['token_type']);
        self::assertCount(0, $history);
    }

    public function testSignedIdTokenWithWrongAudienceNeverFallsBackToIntrospection(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);
        $config = \Lcobucci\JWT\Configuration::forAsymmetricSigner(
            new \Lcobucci\JWT\Signer\Rsa\Sha256(),
            \Lcobucci\JWT\Signer\Key\InMemory::plainText($privateKey),
            \Lcobucci\JWT\Signer\Key\InMemory::plainText($details['key']),
        );
        $now = new \DateTimeImmutable();
        $token = $config->builder()->withHeader('kid', 'test')->issuedBy('https://issuer.test')
            ->permittedFor('other-application')->relatedTo('user-1')->issuedAt($now)
            ->expiresAt($now->modify('+5 minutes'))->getToken($config->signer(), $config->signingKey())->toString();
        $history = [];
        $stack = HandlerStack::create(new MockHandler([]));
        $stack->push(Middleware::history($history));
        $client = $this->client([
            'issuer' => 'https://issuer.test', 'introspection_endpoint' => 'https://issuer.test/introspect',
        ], new Client(['handler' => $stack]));
        $encode = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        (new ReflectionProperty(OidcClient::class, 'jwks'))->setValue($client, ['keys' => [[
            'kid' => 'test', 'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e']),
        ]]]);
        self::assertSame('id_token_validation_failed', $client->resolveUserFromSsoToken($token)['reason']);
        self::assertCount(0, $history);
    }

    public function testPostClientAuthAndUserInfoSubjectBinding(): void
    {
        foreach (['user-1', 'attacker'] as $subject) {
            $history = [];
            $stack = HandlerStack::create(new MockHandler([
                new Response(200, [], '{"active":true,"aud":"claire","client_id":"claire","sub":"user-1"}'),
                new Response(200, [], json_encode(['sub' => $subject, 'given_name' => 'Test'], JSON_THROW_ON_ERROR)),
            ]));
            $stack->push(Middleware::history($history));
            $client = $this->client([
                'introspection_endpoint' => 'https://issuer.test/introspect',
                'introspection_endpoint_auth_methods_supported' => ['client_secret_post'],
                'userinfo_endpoint' => 'https://issuer.test/userinfo',
            ], new Client(['handler' => $stack]));
            $result = $client->resolveUserFromSsoToken('opaque', 'access_token');
            self::assertSame($subject === 'user-1', $result['logged']);
            self::assertCount(2, $history);
            self::assertStringContainsString('client_id=claire&client_secret=secret', (string) $history[0]['request']->getBody());
            self::assertSame('Bearer opaque', $history[1]['request']->getHeaderLine('Authorization'));
            if ($subject === 'user-1') {
                self::assertSame('Test', $result['data']['firstName']);
            }
        }
    }
}
