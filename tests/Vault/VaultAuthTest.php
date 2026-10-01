<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Vault;

use Uengage\PlatformSdk\Client;
use Uengage\PlatformSdk\Exceptions\AuthenticationException;
use Uengage\PlatformSdk\Exceptions\ConfigException;
use Uengage\PlatformSdk\Token\InMemoryTokenCache;
use Uengage\PlatformSdk\Vault\VaultApiException;
use Uengage\PlatformSdk\Vault\VaultClient;
use Uengage\PlatformSdk\Vault\VaultTokenSource;

class VaultAuthTest extends VaultTestCase
{
    // ─── client credentials: minting + caching ──────────────────────────

    public function testForTenantMintsAVaultTokenWithTheVaultGrant(): void
    {
        $vault = $this->credentialsClient();
        $this->pushMint('vault.tok.1');
        $this->stub->pushJson(200, ['id' => 'c1']);

        $vault->forTenant(5, '7175')->credentials()->get('c1');

        $calls = $this->stub->getCalls();
        $this->assertCount(2, $calls);
        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame(self::AUTH . '/oauth/token', $calls[0]['url']);
        $this->assertSame('Basic ' . base64_encode('svc:s3cret'), $calls[0]['headers']['authorization']);
        $this->assertSame('application/x-www-form-urlencoded', $calls[0]['headers']['content-type']);
        $this->assertSame([
            'grant_type' => 'urn:uengage:params:oauth:grant-type:vault-business',
            'parent_id' => '5',
            'business_id' => '7175',
        ], self::form($calls[0]), 'scope and expires_in are omitted by default');
        $this->assertSame('Bearer vault.tok.1', $calls[1]['headers']['authorization']);
        $this->assertSame(self::BASE . '/v1/vault/credentials/c1', $calls[1]['url']);
    }

    public function testForTenantHandleDeletesWithTheMintedToken(): void
    {
        $vault = $this->credentialsClient();
        $this->pushMint('vault.tok.del');
        $this->stub->pushResponse(204, '');
        $vault->forTenant(5)->credentials()->delete('c1');
        $calls = $this->stub->getCalls();
        $this->assertCount(2, $calls);
        $this->assertSame('DELETE', $calls[1]['method']);
        $this->assertSame(self::BASE . '/v1/vault/credentials/c1', $calls[1]['url']);
        $this->assertSame('Bearer vault.tok.del', $calls[1]['headers']['authorization']);
    }

    public function testBrandTokenOmitsBusinessId(): void
    {
        $vault = $this->credentialsClient();
        $this->pushMint();
        $this->stub->pushJson(200, ['id' => 'c1']);
        $vault->forTenant('5')->credentials()->get('c1');
        $this->assertArrayNotHasKey('business_id', self::form($this->stub->getCalls()[0]));
    }

    public function testTokenIsCachedPerTenant(): void
    {
        $vault = $this->credentialsClient();
        $this->pushMint('tok.a');
        $this->stub->pushJson(200, ['id' => 'c1']);
        $this->stub->pushJson(200, ['id' => 'c2']);
        $this->pushMint('tok.b');
        $this->stub->pushJson(200, ['id' => 'c3']);

        $vault->forTenant(5, 6)->credentials()->get('c1');
        $vault->forTenant(5, 6)->credentials()->get('c2');
        $vault->forTenant(5, 7)->credentials()->get('c3');

        $calls = $this->stub->getCalls();
        $this->assertCount(5, $calls);
        $this->assertSame('Bearer tok.a', $calls[1]['headers']['authorization']);
        $this->assertSame('Bearer tok.a', $calls[2]['headers']['authorization']);
        $this->assertSame(self::AUTH . '/oauth/token', $calls[3]['url']);
        $this->assertSame('7', self::form($calls[3])['business_id']);
        $this->assertSame('Bearer tok.b', $calls[4]['headers']['authorization']);
    }

    public function testTokenIsCachedAcrossClientsSharingTheCache(): void
    {
        $this->pushMint('shared.tok');
        $this->stub->pushJson(200, ['id' => 'c1']);
        $this->stub->pushJson(200, ['id' => 'c1']);
        $this->credentialsClient()->forTenant(5)->credentials()->get('c1');
        $this->credentialsClient()->forTenant(5)->credentials()->get('c1');
        $this->assertCount(3, $this->stub->getCalls());
    }

    public function testATokenAtTheMinimumLifetimeIsNotCached(): void
    {
        // 60 s minus the 60 s buffer leaves nothing worth caching.
        $vault = $this->credentialsClient();
        $this->pushMint('short.1', 60);
        $this->stub->pushJson(200, ['id' => 'c1']);
        $this->pushMint('short.2', 60);
        $this->stub->pushJson(200, ['id' => 'c1']);
        $vault->forTenant(5)->credentials()->get('c1');
        $vault->forTenant(5)->credentials()->get('c1');
        $this->assertCount(4, $this->stub->getCalls());
    }

    public function testRemintsOnceOn401AndRetries(): void
    {
        $vault = $this->credentialsClient();
        $this->pushMint('stale');
        $this->stub->pushJson(401, ['error' => 'invalid_token']);
        $this->pushMint('fresh');
        $this->stub->pushJson(200, ['id' => 'c1']);

        $out = $vault->forTenant(5)->credentials()->get('c1');

        $this->assertSame(['id' => 'c1'], $out);
        $calls = $this->stub->getCalls();
        $this->assertCount(4, $calls);
        $this->assertSame('Bearer stale', $calls[1]['headers']['authorization']);
        $this->assertSame(self::AUTH . '/oauth/token', $calls[2]['url']);
        $this->assertSame('Bearer fresh', $calls[3]['headers']['authorization']);
    }

    public function testASecond401IsFinal(): void
    {
        $vault = $this->credentialsClient();
        $this->pushMint('a');
        $this->stub->pushJson(401, ['error' => 'invalid_token']);
        $this->pushMint('b');
        $this->stub->pushJson(401, ['error' => 'invalid_token']);
        try {
            $vault->forTenant(5)->credentials()->get('c1');
            $this->fail('expected VaultApiException');
        } catch (VaultApiException $e) {
            $this->assertSame(401, $e->getStatus());
        }
        $this->assertCount(4, $this->stub->getCalls());
    }

    public function testMintFailureIsAnAuthenticationException(): void
    {
        $vault = $this->credentialsClient();
        $this->stub->pushJson(400, ['error' => 'invalid_scope']);
        $this->expectException(AuthenticationException::class);
        $vault->forTenant(5)->credentials()->get('c1');
    }

    public function testMalformedMintResponseIsRejected(): void
    {
        $vault = $this->credentialsClient();
        $this->stub->pushJson(200, ['token_type' => 'Bearer', 'expires_in' => 300]);
        $this->expectException(AuthenticationException::class);
        $vault->forTenant(5)->credentials()->get('c1');
    }

    /**
     * Same checks as AuthClient::mintVaultToken (shared VaultGrant::checkTokenResponse).
     *
     * @dataProvider badMintResponses
     */
    public function testForTenantRefusesAnUnsafeMintResponse(array $overrides): void
    {
        $vault = $this->credentialsClient();
        $body = array_merge([
            'access_token' => 'vault.jwt',
            'token_type' => 'Bearer',
            'expires_in' => 300,
            'scope' => 'vault.pg:read',
        ], $overrides);
        $this->stub->pushJson(200, array_filter($body, function ($v) {
            return $v !== null;
        }));
        try {
            $vault->forTenant(5)->credentials()->get('c1');
            $this->fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertCount(1, $this->stub->getCalls(), 'no API call with a refused token');
        }
    }

    public function badMintResponses(): array
    {
        return [
            'non-vault scope' => [['scope' => 'vault.pg:read business.profile:read']],
            'lifetime above the maximum' => [['expires_in' => 14401]],
            'lifetime below the minimum' => [['expires_in' => 59]],
            'no token_type' => [['token_type' => null]],
            'no scope' => [['scope' => null]],
        ];
    }

    public function testMintWithRequestedScopesAndLifetimeRefusesMore(): void
    {
        $mint = function (array $body) {
            $this->stub->pushJson(200, $body + ['access_token' => 'vault.jwt', 'token_type' => 'Bearer']);
            return VaultTokenSource::mint(
                $this->stub->client,
                self::AUTH,
                'svc',
                's3cret',
                ['parentId' => '5'],
                ['vault.pg:read'],
                900
            );
        };
        $ok = $mint(['scope' => 'vault.pg:read', 'expires_in' => 900]);
        $this->assertSame('vault.pg:read', $ok['scope']);
        foreach ([
            ['scope' => 'vault.pg:read vault.pg:decrypt', 'expires_in' => 900],
            ['scope' => 'vault.pg:read', 'expires_in' => 3600],
        ] as $bad) {
            try {
                $mint($bad);
                $this->fail('expected AuthenticationException for ' . json_encode($bad));
            } catch (AuthenticationException $e) {
                $this->assertSame('Invalid vault token response', $e->getMessage());
            }
        }
    }

    public function testCredentialsWithoutATenantIsAConfigError(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('forTenant');
        $this->credentialsClient()->credentials();
    }

    public function testTypesWithoutATenantUseTheServiceToken(): void
    {
        $vault = $this->credentialsClient();
        $this->stub->pushJson(200, ['access_token' => 'service.tok', 'expires_in' => 3600]);
        $this->stub->pushJson(200, ['items' => []]);
        $vault->types()->list();
        $calls = $this->stub->getCalls();
        $this->assertSame('grant_type=client_credentials', $calls[0]['body']);
        $this->assertSame('Bearer service.tok', $calls[1]['headers']['authorization']);
    }

    /**
     * @dataProvider badIds
     * @param mixed $parentId
     * @param mixed $businessId
     */
    public function testRejectsBadTenantIds($parentId, $businessId): void
    {
        $this->expectException(ConfigException::class);
        $this->credentialsClient()->forTenant($parentId, $businessId);
    }

    public function badIds(): array
    {
        return [
            'zero' => [0, null],
            'negative' => [-5, null],
            'leading zero' => ['05', null],
            'non-numeric' => ['abc', null],
            'bad outlet' => [5, 'x'],
            'float' => [5.0, null],
        ];
    }

    // ─── static token ────────────────────────────────────────────────────

    public function testStaticTokenIsSentAsIs(): void
    {
        $jwt = self::vaultJwt('5', '6');
        $vault = $this->staticClient($jwt);
        $this->stub->pushJson(200, ['items' => []]);
        $vault->credentials()->list(['class' => 'pg']);
        $this->assertSame('Bearer ' . $jwt, $this->stub->lastCall()['headers']['authorization']);
    }

    public function testStaticTokenWithoutGetTokenDoesNotRetryA401(): void
    {
        $vault = $this->staticClient(self::vaultJwt('5'));
        $this->stub->pushJson(401, ['error' => 'invalid_token']);
        try {
            $vault->credentials()->get('c1');
            $this->fail('expected VaultApiException');
        } catch (VaultApiException $e) {
            $this->assertSame(401, $e->getStatus());
        }
        $this->assertCount(1, $this->stub->getCalls());
    }

    public function testGetTokenIsCalledOnceAfterA401AndTheRequestRetried(): void
    {
        $calls = 0;
        $fresh = self::vaultJwt('5');
        $vault = $this->staticClient(self::vaultJwt('5'), function () use (&$calls, $fresh) {
            $calls++;
            return ['accessToken' => $fresh, 'expiresIn' => 300];
        });
        $this->stub->pushJson(401, ['error' => 'invalid_token']);
        $this->stub->pushJson(200, ['id' => 'c1']);

        $vault->credentials()->get('c1');

        $this->assertSame(1, $calls);
        $this->assertSame('Bearer ' . $fresh, $this->stub->lastCall()['headers']['authorization']);
        $this->assertCount(2, $this->stub->getCalls());
    }

    public function testGetTokenAloneSuppliesTheFirstToken(): void
    {
        $jwt = self::vaultJwt('5');
        $vault = $this->staticClient(null, function () use ($jwt) {
            return $jwt;
        });
        $this->stub->pushJson(200, ['id' => 'c1']);
        $vault->credentials()->get('c1');
        $this->assertSame('Bearer ' . $jwt, $this->stub->lastCall()['headers']['authorization']);
    }

    public function testGetTokenIsCalledWhenTheStaticTokenHasExpired(): void
    {
        $expired = self::vaultJwt('5', null, -10);
        $fresh = self::vaultJwt('5');
        $vault = $this->staticClient($expired, function () use ($fresh) {
            return $fresh;
        });
        $this->stub->pushJson(200, ['id' => 'c1']);
        $vault->credentials()->get('c1');
        $this->assertSame('Bearer ' . $fresh, $this->stub->lastCall()['headers']['authorization']);
    }

    public function testNoTokenAtAllIsAConfigError(): void
    {
        $this->expectException(ConfigException::class);
        $this->staticClient(null)->credentials()->get('c1');
    }

    public function testForTenantMatchingTheTokenIsAllowed(): void
    {
        $vault = $this->staticClient(self::vaultJwt('5', '6'));
        $handle = $vault->forTenant(5, 6);
        $this->assertSame(['parentId' => '5', 'businessId' => '6'], $handle->tenant());
    }

    /**
     * @dataProvider mismatches
     */
    public function testForTenantMismatchIsAConfigError(string $jwtParent, ?string $jwtBusiness, int $parent, ?int $business): void
    {
        $vault = $this->staticClient(self::vaultJwt($jwtParent, $jwtBusiness));
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('does not match');
        $vault->forTenant($parent, $business);
    }

    public function mismatches(): array
    {
        return [
            'other brand' => ['5', null, 9, null],
            'brand token, outlet asked' => ['5', null, 5, 6],
            'outlet token, brand asked' => ['5', '6', 5, null],
            'other outlet' => ['5', '6', 5, 7],
        ];
    }

    public function testForTenantOnANonVaultTokenIsAConfigError(): void
    {
        $vault = $this->staticClient('opaque-token');
        $this->expectException(ConfigException::class);
        $vault->forTenant(5);
    }

    // ─── Client::create wiring ──────────────────────────────────────────

    public function testClientCreateWiresVault(): void
    {
        $client = Client::create([
            'baseUrl' => self::BASE,
            'serviceId' => 'svc',
            'serviceSecret' => 's3cret',
            'cache' => new InMemoryTokenCache(),
            'http' => $this->stub->client,
        ]);
        $this->assertInstanceOf(VaultClient::class, $client->vault);
        $this->assertTrue($client->vault->hasClientCredentials());
    }

    public function testClientCreateStaticTokenModeIsNotCredentials(): void
    {
        $client = Client::create([
            'baseUrl' => self::BASE,
            'authToken' => self::vaultJwt('5'),
            'getToken' => function () {
                return 'x';
            },
            'cache' => new InMemoryTokenCache(),
            'http' => $this->stub->client,
        ]);
        $this->assertFalse($client->vault->hasClientCredentials());
    }

    public function testClientCreateRejectsGetTokenWithClientCredentials(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('getToken');
        Client::create([
            'baseUrl' => self::BASE,
            'serviceId' => 'svc',
            'serviceSecret' => 's3cret',
            'getToken' => function () {
                return 'x';
            },
            'cache' => new InMemoryTokenCache(),
            'http' => $this->stub->client,
        ]);
    }
}
