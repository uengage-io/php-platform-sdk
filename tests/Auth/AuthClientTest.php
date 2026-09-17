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

    public function testMintRealtimeTokenUsesExactNameAndDoesNotShareTokensAcrossChannels(): void
    {
        [$stub, $client] = $this->makeClient();
        foreach (['delivery-updates', 'private-updates'] as $channel) {
            $stub->pushJson(200, ['access_token' => 'jwt-' . $channel, 'expires_in' => 300, 'channel' => $channel]);
            $result = $client->mintRealtimeToken('backend', 'secret', $channel);
            $this->assertSame(['token' => 'jwt-' . $channel, 'expiresIn' => 300, 'channel' => $channel], $result);
            $call = $stub->lastCall();
            parse_str($call['body'], $body);
            $this->assertSame($channel, $body['channel']);
            $this->assertSame('urn:uengage:params:oauth:grant-type:realtime-channel', $body['grant_type']);
            $this->assertSame('Basic ' . base64_encode('backend:secret'), $call['headers']['authorization']);
        }
    }

    public function testRealtimeTokenPassesUserAttribution(): void
    {
        [$stub, $client] = $this->makeClient();
        $stub->pushJson(200, ['access_token' => 'jwt', 'expires_in' => 300, 'channel' => 'delivery-updates']);
        $client->mintRealtimeToken('backend', 'secret', 'delivery-updates', 300, 'user-42');
        parse_str($stub->lastCall()['body'], $body);
        $this->assertSame('user-42', $body['on_behalf_of']);
    }

    public function testRealtimeTokenRejectsInvalidAttributionBeforeSending(): void
    {
        [$stub, $client] = $this->makeClient();
        $this->expectException(InvalidArgumentException::class);
        $client->mintRealtimeToken('backend', 'secret', 'delivery-updates', 300, "user\n");
    }

    public function testRealtimeTokenRejectsWildcardBeforeSending(): void
    {
        [$stub, $client] = $this->makeClient();
        $this->expectException(InvalidArgumentException::class);
        $client->mintRealtimeToken('backend', 'secret', '*');
    }

    /** @dataProvider outOfRangeLifetimes */
    public function testMintsRejectLifetimesOutsideTheAllowedRange(int $expiresIn): void
    {
        [$stub, $client] = $this->makeClient();
        $this->expectException(InvalidArgumentException::class);
        $client->mintRealtimeToken('backend', 'secret', 'delivery-updates', $expiresIn);
    }

    /** @dataProvider outOfRangeLifetimes */
    public function testUpsellMintRejectsLifetimesOutsideTheAllowedRange(int $expiresIn): void
    {
        [$stub, $client] = $this->makeClient();
        $this->expectException(InvalidArgumentException::class);
        $client->mintUpsellToken('backend', 'secret', 5, 7175, 'public', null, $expiresIn);
    }

    public function outOfRangeLifetimes(): array
    {
        return [[59], [14401]];
    }

    public function testRealtimeTokenAcceptsTheFourHourCeiling(): void
    {
        [$stub, $client] = $this->makeClient();
        $stub->pushJson(200, [
            'access_token' => 'jwt',
            'expires_in' => 14400,
            'channel' => 'delivery-updates',
        ]);
        $result = $client->mintRealtimeToken('backend', 'secret', 'delivery-updates', 14400);
        $this->assertSame(14400, $result['expiresIn']);
    }

    public function testRealtimeTokenRejectsWrongChannelInResponse(): void
    {
        [$stub, $client] = $this->makeClient();
        $stub->pushJson(200, ['access_token' => 'jwt', 'expires_in' => 300, 'channel' => 'other']);
        $this->expectException(AuthApiException::class);
        $client->mintRealtimeToken('backend', 'secret', 'delivery-updates');
    }

    public function testMintUpsellTokenBindsPublicAndPrivateTokensToTheirOutletAndMapping(): void
    {
        [$stub, $client] = $this->makeClient();
        foreach ([null, 123, 124] as $mapping) {
            $access = $mapping === null ? 'public' : 'private';
            $body = ['access_token' => 'jwt-' . $mapping, 'token_type' => 'Bearer', 'expires_in' => 300,
                'parent_id' => 5, 'business_id' => 7175, 'access' => $access, 'scope' => 'upsell.suggestions:' . $access];
            if ($mapping !== null) $body['contact_mapping_id'] = $mapping;
            $stub->pushJson(200, $body);
            $result = $client->mintUpsellToken('backend', 'secret', 5, 7175, $access, $mapping);
            $this->assertSame('jwt-' . $mapping, $result['token']);
            $this->assertSame(7175, $result['businessId']);
            $this->assertSame($mapping, $result['contactMappingId'] ?? null);
            $call = $stub->lastCall();
            parse_str($call['body'], $form);
            $this->assertSame('urn:uengage:params:oauth:grant-type:upsell', $form['grant_type']);
            $this->assertSame('Basic ' . base64_encode('backend:secret'), $call['headers']['authorization']);
            if ($mapping === null) $this->assertArrayNotHasKey('contact_mapping_id', $form);
            else $this->assertSame((string) $mapping, $form['contact_mapping_id']);
        }
        $this->assertCount(3, $stub->getCalls());
    }

    public function testMintUpsellAdminTokenRequestsOutletPrivileges(): void
    {
        [$stub, $client] = $this->makeClient();
        $stub->pushJson(200, ['access_token' => 'admin-jwt', 'token_type' => 'Bearer', 'expires_in' => 300,
            'parent_id' => 5, 'business_id' => 7175, 'access' => 'admin',
            'admin_scope' => 'outlet', 'scope' => 'upsell.placements:read upsell.placements:write']);
        $result = $client->mintUpsellToken('backend', 'secret', 5, 7175, 'admin');
        $this->assertSame('admin', $result['access']);
        parse_str($stub->lastCall()['body'], $form);
        $this->assertSame('admin', $form['access']);
        $this->assertArrayNotHasKey('contact_mapping_id', $form);
    }

    public function testMintUpsellBrandAndGlobalAdminTokens(): void
    {
        [$stub, $client] = $this->makeClient();
        foreach (['brand', 'global'] as $adminScope) {
            $parentId = $adminScope === 'brand' ? 5 : null;
            $body = ['access_token' => 'admin-jwt', 'token_type' => 'Bearer', 'expires_in' => 300,
                'access' => 'admin', 'admin_scope' => $adminScope,
                'scope' => 'upsell.placements:read upsell.placements:write upsell.touchpoints:read upsell.touchpoints:write'];
            if ($parentId !== null) $body['parent_id'] = $parentId;
            $stub->pushJson(200, $body);
            $result = $client->mintUpsellToken('backend', 'secret', $parentId, null, 'admin', null, 300, $adminScope);
            $this->assertSame($adminScope, $result['adminScope']);
            parse_str($stub->lastCall()['body'], $form);
            $this->assertSame($adminScope, $form['admin_scope']);
            $this->assertArrayNotHasKey('business_id', $form);
            if ($adminScope === 'global') $this->assertArrayNotHasKey('parent_id', $form);
        }
    }

    public function testGlobalAdminTokenRejectsTenantFields(): void
    {
        [, $client] = $this->makeClient();
        $this->expectException(InvalidArgumentException::class);
        $client->mintUpsellToken('backend', 'secret', 5, null, 'admin', null, 300, 'global');
    }

    public function testUpsellPrivateTokenRequiresAMapping(): void
    {
        [, $client] = $this->makeClient();
        $this->expectException(InvalidArgumentException::class);
        $client->mintUpsellToken('backend', 'secret', 5, 7175, 'private');
    }

    public function testUpsellPublicTokenForbidsAMapping(): void
    {
        [, $client] = $this->makeClient();
        $this->expectException(InvalidArgumentException::class);
        $client->mintUpsellToken('backend', 'secret', 5, 7175, 'public', 123);
    }

    public function testUpsellTokenRejectsAnotherOutletInResponse(): void
    {
        [$stub, $client] = $this->makeClient();
        $stub->pushJson(200, ['access_token' => 'jwt', 'token_type' => 'Bearer', 'expires_in' => 300,
            'parent_id' => 5, 'business_id' => 7176, 'access' => 'public', 'scope' => 'upsell.suggestions:public']);
        $this->expectException(AuthApiException::class);
        $client->mintUpsellToken('backend', 'secret', 5, 7175);
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
