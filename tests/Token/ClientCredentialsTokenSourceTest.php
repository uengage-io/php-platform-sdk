<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Token;

use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Exceptions\AuthenticationException;
use Uengage\PlatformSdk\Tests\Support\StubHttp;
use Uengage\PlatformSdk\Token\ClientCredentialsTokenSource;
use Uengage\PlatformSdk\Token\InMemoryTokenCache;

class ClientCredentialsTokenSourceTest extends TestCase
{
    public function testMintsAndCachesToken(): void
    {
        $stub = new StubHttp();
        $stub->pushJson(200, ['access_token' => 'jwt-1', 'token_type' => 'Bearer', 'expires_in' => 900]);
        $cache = new InMemoryTokenCache();
        $source = new ClientCredentialsTokenSource(
            $stub->client,
            $cache,
            'https://api.test/auth/business',
            'cid',
            'sec'
        );

        $this->assertSame('jwt-1', $source->getAccessToken());
        // Second call should hit the cache and not mint again.
        $this->assertSame('jwt-1', $source->getAccessToken());
        $this->assertCount(1, $stub->getCalls());
    }

    public function testFirstMintUsesBasicAuthAndCorrectGrant(): void
    {
        $stub = new StubHttp();
        $stub->pushJson(200, ['access_token' => 'jwt', 'expires_in' => 900]);
        $source = new ClientCredentialsTokenSource(
            $stub->client,
            new InMemoryTokenCache(),
            'https://api.test/auth/business',
            'cid',
            'sec'
        );
        $source->getAccessToken();
        $call = $stub->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://api.test/auth/business/oauth/token', $call['url']);
        $this->assertSame('application/x-www-form-urlencoded', $call['headers']['content-type']);
        $this->assertSame('Basic ' . base64_encode('cid:sec'), $call['headers']['authorization']);
        $this->assertSame('grant_type=client_credentials', $call['body']);
    }

    public function testIncludesScopeInBodyWhenProvided(): void
    {
        $stub = new StubHttp();
        $stub->pushJson(200, ['access_token' => 'jwt', 'expires_in' => 900]);
        $source = new ClientCredentialsTokenSource(
            $stub->client,
            new InMemoryTokenCache(),
            'https://api.test/auth/business',
            'cid',
            'sec',
            'business.profile:read zones.write'
        );
        $source->getAccessToken();
        $this->assertSame(
            'grant_type=client_credentials&scope=business.profile%3Aread%20zones.write',
            $stub->lastCall()['body']
        );
    }

    public function testInvalidateForcesNextCallToMint(): void
    {
        $stub = new StubHttp();
        $stub->pushJson(200, ['access_token' => 'jwt-1', 'expires_in' => 900]);
        $stub->pushJson(200, ['access_token' => 'jwt-2', 'expires_in' => 900]);
        $source = new ClientCredentialsTokenSource(
            $stub->client,
            new InMemoryTokenCache(),
            'https://api.test/auth/business',
            'cid',
            'sec'
        );
        $this->assertSame('jwt-1', $source->getAccessToken());
        $source->invalidate();
        $this->assertSame('jwt-2', $source->getAccessToken());
        $this->assertCount(2, $stub->getCalls());
    }

    public function testThrowsAuthenticationExceptionOnBadStatus(): void
    {
        $stub = new StubHttp();
        $stub->pushJson(401, ['error' => 'invalid_client']);
        $source = new ClientCredentialsTokenSource(
            $stub->client,
            new InMemoryTokenCache(),
            'https://api.test/auth/business',
            'cid',
            'wrong'
        );
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('client_credentials mint failed: HTTP 401');
        $source->getAccessToken();
    }

    public function testThrowsOnMissingAccessTokenField(): void
    {
        $stub = new StubHttp();
        $stub->pushJson(200, ['expires_in' => 900]);
        $source = new ClientCredentialsTokenSource(
            $stub->client,
            new InMemoryTokenCache(),
            'https://api.test/auth/business',
            'cid',
            'sec'
        );
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('access_token');
        $source->getAccessToken();
    }
}
