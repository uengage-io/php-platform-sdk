<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Vault;

use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Vault\VaultGrant;

/**
 * Drift guard for the copied vault constants.
 *
 * `packages/common/src/vault-grant.ts` and `token-lifetime.ts` are the
 * source of truth; this package is published to Packagist and keeps a copy
 * in VaultGrant. The TypeScript is read as text (the same way
 * DomainMapTest reads domains.json) and skipped outside the monorepo.
 */
class VaultGrantDriftTest extends TestCase
{
    private function source(string $file): string
    {
        $path = __DIR__ . '/../../../common/src/' . $file;
        if (!file_exists($path)) {
            $this->markTestSkipped($file . ' not found at ' . $path . ' - expected outside the monorepo.');
        }
        return (string) file_get_contents($path);
    }

    private function constString(string $src, string $name): string
    {
        $this->assertSame(
            1,
            preg_match('/export const ' . $name . "\\s*=\\s*'([^']*)'/", $src, $m),
            $name . ' not found'
        );
        return $m[1];
    }

    /**
     * @return array<int, string>
     */
    private function stringArray(string $src, string $pattern, string $label): array
    {
        $this->assertSame(1, preg_match($pattern, $src, $m), $label . ' not found');
        preg_match_all("/'([^']*)'/", $m[1], $items);
        return $items[1];
    }

    private function constInt(string $src, string $name): int
    {
        $this->assertSame(
            1,
            preg_match('/export const ' . $name . '\s*=\s*([0-9_]+)\s*;/', $src, $m),
            $name . ' not found'
        );
        return (int) str_replace('_', '', $m[1]);
    }

    public function testGrantStringsMatch(): void
    {
        $src = $this->source('vault-grant.ts');
        $this->assertSame($this->constString($src, 'VAULT_TOKEN_GRANT'), VaultGrant::GRANT_TYPE);
        $this->assertSame($this->constString($src, 'VAULT_TOKEN_USE'), VaultGrant::TOKEN_USE);
        $this->assertSame($this->constString($src, 'VAULT_TOKEN_ISSUE_SCOPE'), VaultGrant::TOKENS_ISSUE_SCOPE);
        $this->assertSame($this->constString($src, 'VAULT_TYPES_READ_SCOPE'), VaultGrant::TYPES_READ_SCOPE);
        $this->assertSame($this->constString($src, 'VAULT_TYPES_WRITE_SCOPE'), VaultGrant::TYPES_WRITE_SCOPE);
    }

    public function testClassesAndScopesMatch(): void
    {
        $src = $this->source('vault-grant.ts');
        $credentialClasses = $this->stringArray(
            $src,
            '/export const VAULT_CREDENTIAL_CLASSES\s*=\s*\[([^\]]*)\]/',
            'VAULT_CREDENTIAL_CLASSES'
        );
        $documentClasses = $this->stringArray(
            $src,
            '/export const VAULT_DOCUMENT_CLASSES\s*=\s*\[([^\]]*)\]/',
            'VAULT_DOCUMENT_CLASSES'
        );
        $credentialVerbs = $this->stringArray($src, '/const CREDENTIAL_VERBS[^=]*=\s*\[([^\]]*)\]/', 'CREDENTIAL_VERBS');
        $documentVerbs = $this->stringArray($src, '/const DOCUMENT_VERBS[^=]*=\s*\[([^\]]*)\]/', 'DOCUMENT_VERBS');

        $this->assertSame($credentialClasses, VaultGrant::CREDENTIAL_CLASSES);
        $this->assertSame($documentClasses, VaultGrant::DOCUMENT_CLASSES);

        // Rebuild VAULT_TOKEN_SCOPES the way the TypeScript does.
        $expected = [];
        foreach ($credentialClasses as $class) {
            foreach ($credentialVerbs as $verb) {
                $expected[] = 'vault.' . $class . ':' . $verb;
            }
        }
        foreach ($documentClasses as $class) {
            foreach ($documentVerbs as $verb) {
                $expected[] = 'vault.' . $class . ':' . $verb;
            }
        }
        $expected[] = $this->constString($src, 'VAULT_TYPES_READ_SCOPE');
        $expected[] = $this->constString($src, 'VAULT_TYPES_WRITE_SCOPE');
        $this->assertSame($expected, VaultGrant::TOKEN_SCOPES);
        $this->assertNotContains(VaultGrant::TOKENS_ISSUE_SCOPE, VaultGrant::TOKEN_SCOPES);
    }

    public function testLifetimeBoundsMatch(): void
    {
        $src = $this->source('token-lifetime.ts');
        $this->assertSame($this->constInt($src, 'MIN_TOKEN_TTL_SECONDS'), VaultGrant::MIN_TTL_SECONDS);
        $this->assertSame($this->constInt($src, 'MAX_TOKEN_TTL_SECONDS'), VaultGrant::MAX_TTL_SECONDS);
        $this->assertSame($this->constInt($src, 'DEFAULT_TOKEN_TTL_SECONDS'), VaultGrant::DEFAULT_TTL_SECONDS);
    }
}
