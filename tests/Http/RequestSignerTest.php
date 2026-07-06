<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Http;

use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Http\RequestSigner;
use Uengage\PlatformSdk\Tests\Support\StubHttp;
use Uengage\PlatformSdk\Token\StaticBearerTokenSource;

class RequestSignerTest extends TestCase
{
    public function testGetSetsUserAgentButNoIdempotencyKey(): void
    {
        $stub = new StubHttp();
        $config = new Config('https://api.test', 'https://api.test/auth/business', 'https://api.test/auth/customer', null);
        $signer = new RequestSigner($config, $stub->client);
        $stub->pushJson(200, ['ok' => true]);

        $signer->send('GET', '/v1/things', $signer->url('/v1/things'));
        $call = $stub->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('https://api.test/v1/things', $call['url']);
        $this->assertArrayHasKey('User-Agent', $call['headers']);
        $this->assertArrayNotHasKey('idempotency-key', $call['headers']);
        $this->assertArrayNotHasKey('authorization', $call['headers']);
    }

    public function testPostAttachesIdempotencyKeyAndJsonContentType(): void
    {
        $stub = new StubHttp();
        $config = new Config('https://api.test', 'https://api.test/auth/business', 'https://api.test/auth/customer', null);
        $signer = new RequestSigner($config, $stub->client);
        $stub->pushJson(201, ['zid' => '11111111-2222-3333-4444-555555555555']);

        $signer->send('POST', '/v1/things', $signer->url('/v1/things'), '{}');
        $headers = $stub->lastCall()['headers'];
        $this->assertSame('application/json', $headers['content-type']);
        $this->assertArrayHasKey('idempotency-key', $headers);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $headers['idempotency-key']
        );
    }

    public function testAuthorizationHeaderUsesTokenSource(): void
    {
        $stub = new StubHttp();
        $token = new StaticBearerTokenSource('test.bearer.jwt');
        $config = new Config('https://api.test', 'https://api.test/auth/business', 'https://api.test/auth/customer', $token);
        $signer = new RequestSigner($config, $stub->client);
        $stub->pushJson(200, ['ok' => true]);

        $signer->send('GET', '/v1/things', $signer->url('/v1/things'));
        $this->assertSame('Bearer test.bearer.jwt', $stub->lastCall()['headers']['authorization']);
    }

    public function testRetriesOnceOn401AndInvalidatesToken(): void
    {
        $stub = new StubHttp();
        $tokenSource = new class implements \Uengage\PlatformSdk\Token\TokenSourceInterface {
            public $invalidationCount = 0;
            public function getAccessToken(): string
            {
                return 'jwt';
            }
            public function invalidate(): void
            {
                $this->invalidationCount++;
            }
        };
        $config = new Config('https://api.test', 'https://api.test/auth/business', 'https://api.test/auth/customer', $tokenSource);
        $signer = new RequestSigner($config, $stub->client);
        $stub->pushJson(401, ['error' => 'expired']);
        $stub->pushJson(200, ['ok' => true]);

        $response = $signer->send('GET', '/v1/things', $signer->url('/v1/things'));
        $this->assertSame(200, $response->getStatus());
        $this->assertCount(2, $stub->getCalls());
        $this->assertSame(1, $tokenSource->invalidationCount);
    }
}
