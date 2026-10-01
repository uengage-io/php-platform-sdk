<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Alerts;

use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Alerts\AlertsGrant;

/**
 * Drift guard for the copied alerts token constants.
 *
 * `packages/common/src/alerts-grant.ts` and `token-lifetime.ts` are the
 * source of truth; AlertsGrant keeps a copy. The TypeScript is read as text
 * and the test is skipped outside the monorepo.
 */
class AlertsGrantDriftTest extends TestCase
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
        $src = $this->source('alerts-grant.ts');
        $this->assertSame($this->constString($src, 'ALERTS_TOKEN_GRANT'), AlertsGrant::GRANT_TYPE);
        $this->assertSame($this->constString($src, 'ALERTS_TOKEN_USE'), AlertsGrant::TOKEN_USE);
        $this->assertSame($this->constString($src, 'ALERTS_TOKEN_ISSUE_SCOPE'), AlertsGrant::TOKENS_ISSUE_SCOPE);
        $this->assertSame($this->constString($src, 'ALERTS_READ_SCOPE'), AlertsGrant::READ_SCOPE);
        $this->assertSame($this->constString($src, 'ALERTS_WRITE_SCOPE'), AlertsGrant::WRITE_SCOPE);
    }

    public function testTokenScopesMatch(): void
    {
        $src = $this->source('alerts-grant.ts');
        $this->assertSame(
            1,
            preg_match('/export const ALERTS_TOKEN_SCOPES[^=]*=\s*\[([^\]]*)\]/', $src, $m),
            'ALERTS_TOKEN_SCOPES not found'
        );
        preg_match_all('/[A-Z_]+/', $m[1], $names);
        $expected = [];
        foreach ($names[0] as $name) {
            $expected[] = $this->constString($src, $name);
        }
        $this->assertSame($expected, AlertsGrant::TOKEN_SCOPES);
        $this->assertNotContains(AlertsGrant::TOKENS_ISSUE_SCOPE, AlertsGrant::TOKEN_SCOPES);
    }

    public function testUserIdPatternMatches(): void
    {
        $src = $this->source('alerts-grant.ts');
        $this->assertSame(
            1,
            preg_match('/function isAlertsUserId[\s\S]*?\/\^(\[[^\]]*\]\{\d+,\d+\})\$\//', $src, $m),
            'isAlertsUserId pattern not found'
        );
        $this->assertSame('/\A' . $m[1] . '\z/', AlertsGrant::USER_ID_PATTERN);
    }

    public function testLifetimeBoundsMatch(): void
    {
        $src = $this->source('token-lifetime.ts');
        $this->assertSame($this->constInt($src, 'MIN_TOKEN_TTL_SECONDS'), AlertsGrant::MIN_TTL_SECONDS);
        $this->assertSame($this->constInt($src, 'MAX_TOKEN_TTL_SECONDS'), AlertsGrant::MAX_TTL_SECONDS);
        $this->assertSame($this->constInt($src, 'DEFAULT_TOKEN_TTL_SECONDS'), AlertsGrant::DEFAULT_TTL_SECONDS);
    }
}
