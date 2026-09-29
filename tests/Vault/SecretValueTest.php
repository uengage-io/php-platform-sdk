<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Vault;

use LogicException;
use RuntimeException;
use Uengage\PlatformSdk\Vault\SecretValue;

/**
 * No accidental path from a decrypted value to text: casts, JSON, dumps,
 * serialisation, exceptions - including over a full decrypt round trip.
 */
class SecretValueTest extends VaultTestCase
{
    const PLAIN = 'rzp_live_TOP_SECRET_9f8e7d';

    public function testRevealReturnsThePlaintext(): void
    {
        $this->assertSame(self::PLAIN, (new SecretValue(self::PLAIN))->reveal());
    }

    public function testStringCastAndInterpolationAreRedacted(): void
    {
        $s = new SecretValue(self::PLAIN);
        $this->assertSame('[REDACTED]', (string) $s);
        $this->assertSame('key=[REDACTED]', "key=$s");
        $this->assertSame('key=[REDACTED]', sprintf('key=%s', $s));
    }

    public function testJsonEncodeIsRedacted(): void
    {
        $json = json_encode(['value' => ['key_secret' => new SecretValue(self::PLAIN)]]);
        $this->assertSame('{"value":{"key_secret":"[REDACTED]"}}', $json);
    }

    public function testDumpsAreRedacted(): void
    {
        $s = new SecretValue(self::PLAIN);
        $printed = print_r(['v' => $s], true);
        ob_start();
        var_dump($s);
        $dumped = (string) ob_get_clean();
        $exported = var_export(['v' => $s], true);

        foreach ([$printed, $dumped, $exported] as $out) {
            $this->assertStringNotContainsString(self::PLAIN, $out);
        }
        $this->assertStringContainsString('[REDACTED]', $printed);
        $this->assertStringContainsString('[REDACTED]', $dumped);
    }

    public function testArrayCastDoesNotExposeThePlaintext(): void
    {
        $this->assertStringNotContainsString(self::PLAIN, print_r((array) new SecretValue(self::PLAIN), true));
    }

    public function testSerializeThrows(): void
    {
        $this->expectException(LogicException::class);
        serialize(new SecretValue(self::PLAIN));
    }

    public function testExceptionTracesDoNotCarryThePlaintext(): void
    {
        $thrower = function (SecretValue $s) {
            throw new RuntimeException('failed with ' . $s);
        };
        try {
            $thrower(new SecretValue(self::PLAIN));
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString(self::PLAIN, (string) $e);
            $this->assertStringNotContainsString(self::PLAIN, $e->getTraceAsString());
        }
    }

    public function testAFullDecryptLeavesNoPlaintextInAnyRendering(): void
    {
        $vault = $this->staticClient(self::vaultJwt('5'));
        $this->stub->pushJson(200, ['items' => [[
            'id' => 'c1',
            'kind' => 'credential',
            'type' => 'pg.razorpay',
            'value' => ['key_id' => 'rzp_live_id', 'key_secret' => self::PLAIN],
        ]]]);

        $credential = $vault->credentials()->decrypt(['id' => 'c1']);

        $this->assertStringNotContainsString(self::PLAIN, (string) json_encode($credential));
        $this->assertStringNotContainsString(self::PLAIN, print_r($credential, true));
        $this->assertStringNotContainsString(self::PLAIN, var_export($credential, true));
        ob_start();
        var_dump($credential);
        $this->assertStringNotContainsString(self::PLAIN, (string) ob_get_clean());
        $this->assertSame(self::PLAIN, $credential['value']['key_secret']->reveal());
    }
}
