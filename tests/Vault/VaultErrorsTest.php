<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Vault;

use Uengage\PlatformSdk\Exceptions\ApiException;
use Uengage\PlatformSdk\Http\HttpResponse;
use Uengage\PlatformSdk\Vault\VaultApiException;
use Uengage\PlatformSdk\Vault\VaultConflictException;
use Uengage\PlatformSdk\Vault\VaultDeniedException;
use Uengage\PlatformSdk\Vault\VaultNotFoundException;
use Uengage\PlatformSdk\Vault\VaultUnavailableException;
use Uengage\PlatformSdk\Vault\VaultValidationException;

class VaultErrorsTest extends VaultTestCase
{
    /**
     * @dataProvider statuses
     */
    public function testStatusMapsToASubclass(int $status, string $class, string $code): void
    {
        $vault = $this->staticClient(self::vaultJwt('5'));
        $this->stub->pushJson($status, ['error' => $code]);
        try {
            $vault->credentials()->get('c1');
            $this->fail('expected ' . $class);
        } catch (VaultApiException $e) {
            $this->assertInstanceOf($class, $e);
            $this->assertInstanceOf(ApiException::class, $e);
            $this->assertSame($status, $e->getStatus());
            $this->assertSame($code, $e->getErrorCode());
        }
    }

    public function statuses(): array
    {
        return [
            'bad request' => [400, VaultValidationException::class, 'invalid_request'],
            'unknown type' => [422, VaultValidationException::class, 'unknown_type'],
            'denied' => [403, VaultDeniedException::class, 'forbidden'],
            'not found' => [404, VaultNotFoundException::class, 'not_found'],
            'conflict' => [409, VaultConflictException::class, 'reference_conflict'],
            'server' => [500, VaultUnavailableException::class, 'internal'],
            'unavailable' => [503, VaultUnavailableException::class, 'unavailable'],
            'rate limited' => [429, VaultApiException::class, 'too_many_requests'],
        ];
    }

    public function testNonJsonErrorBodyHasNoCode(): void
    {
        $e = VaultApiException::fromResponse(502, '<html>bad gateway</html>');
        $this->assertInstanceOf(VaultUnavailableException::class, $e);
        $this->assertNull($e->getErrorCode());
        $this->assertSame('<html>bad gateway</html>', $e->getBody());
    }

    public function testTransportFailureIsUnavailable(): void
    {
        $vault = $this->staticClient(self::vaultJwt('5'));
        $this->stub->client->setFetchOverrideForTesting(function () {
            throw new ApiException('http', 0, 'cURL transport error (7): connection refused');
        });
        try {
            $vault->credentials()->get('c1');
            $this->fail('expected VaultUnavailableException');
        } catch (VaultUnavailableException $e) {
            $this->assertSame(0, $e->getStatus());
        }
    }

    public function testNonJsonSuccessIsUnavailable(): void
    {
        $vault = $this->staticClient(self::vaultJwt('5'));
        $this->stub->client->setFetchOverrideForTesting(function () {
            return new HttpResponse(200, [], 'not json');
        });
        $this->expectException(VaultUnavailableException::class);
        $vault->credentials()->get('c1');
    }
}
