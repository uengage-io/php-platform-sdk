<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Auth;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Auth\AuthApiException;
use Uengage\PlatformSdk\Auth\AuthClient;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Tests\Support\StubHttp;

class AuthClientTest extends TestCase
{
    private function makeClient(?StubHttp $stub = null): array
    {
        $stub = $stub ?: new StubHttp();
        $config = new Config(
            'https://api.test',
            'https://api.test/auth/business',
            'https://api.test/auth/customer',
            null
        );
        return [$stub, new AuthClient($config, $stub->client)];
    }

    public function testMintClientCredentialsTokenSuccess(): void
    {
        [$stub, $client] = $this->makeClient();
        $stub->pushJson(200, ['access_token' => 'jwt', 'token_type' => 'Bearer', 'expires_in' => 900]);

        $result = $client->mintClientCredentialsToken('cid', 'sec', 'business.profile:read');
        $this->assertSame('jwt', $result['access_token']);

        $call = $stub->lastCall();
        $this->assertSame('https://api.test/auth/business/oauth/token', $call['url']);
        $this->assertSame('Basic ' . base64_encode('cid:sec'), $call['headers']['authorization']);
        $this->assertStringContainsString('scope=business.profile%3Aread', $call['body']);
    }

    public function testMintRejectsEmptyCredentials(): void
    {
        [, $client] = $this->makeClient();
        $this->expectException(InvalidArgumentException::class);
        $client->mintClientCredentialsToken('', 'sec');
    }

    public function testMintSurfaces4xxAsAuthApiException(): void
    {
        [$stub, $client] = $this->makeClient();
        $stub->pushJson(401, ['error' => 'invalid_client']);
        $this->expectException(AuthApiException::class);
        $client->mintClientCredentialsToken('cid', 'wrong');
    }

    public function testRefreshAccessTokenSendsCorrectGrant(): void
    {
        [$stub, $client] = $this->makeClient();
        $stub->pushJson(200, ['access_token' => 'new-jwt', 'refresh_token' => 'new-refresh', 'expires_in' => 900]);

        $result = $client->refreshAccessToken('old-refresh', 'dashboard');
        $this->assertSame('new-jwt', $result['access_token']);

        $body = $stub->lastCall()['body'];
        $this->assertStringContainsString('grant_type=refresh_token', $body);
        $this->assertStringContainsString('refresh_token=old-refresh', $body);
        $this->assertStringContainsString('client_id=dashboard', $body);
    }

    public function testExchangeLegacySessionTriesBusinessFirst(): void
    {
        [$stub, $client] = $this->makeClient();
        $stub->pushJson(200, ['access_token' => 'jwt-from-business', 'expires_in' => 900]);

        $result = $client->exchangeLegacySession(123, 'token');
        $this->assertSame('jwt-from-business', $result['access_token']);
        $this->assertCount(1, $stub->getCalls());
        $this->assertStringContainsString('/auth/business/oauth/token', $stub->lastCall()['url']);
    }

    public function testExchangeLegacySessionFallsBackToCustomerOnInvalidGrant(): void
    {
        [$stub, $client] = $this->makeClient();
        $stub->pushJson(400, ['error' => 'invalid_grant']);
        $stub->pushJson(200, ['access_token' => 'jwt-from-customer', 'expires_in' => 900]);

        $result = $client->exchangeLegacySession(123, 'token');
        $this->assertSame('jwt-from-customer', $result['access_token']);
        $this->assertCount(2, $stub->getCalls());
        $this->assertStringContainsString('/auth/customer/oauth/token', $stub->getCalls()[1]['url']);
    }

    public function testExchangeLegacySessionDoesNotFallBackOnNon400(): void
    {
        [$stub, $client] = $this->makeClient();
        $stub->pushJson(500, ['error' => 'oops']);

        $this->expectException(AuthApiException::class);
        $client->exchangeLegacySession(123, 'token');
    }

    public function testExchangeLegacySessionPropagatesCustomerError(): void
    {
        [$stub, $client] = $this->makeClient();
        $stub->pushJson(400, ['error' => 'invalid_grant']);
        $stub->pushJson(401, ['error' => 'unauthorized']);

        $this->expectException(AuthApiException::class);
        $client->exchangeLegacySession(123, 'token');
    }
}
